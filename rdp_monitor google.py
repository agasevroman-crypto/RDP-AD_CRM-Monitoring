import tkinter as tk
from tkinter import ttk, scrolledtext, messagebox, filedialog
import threading
import time
import requests
import win32ts
import logging
import sqlite3
import json
import os
import sys
import socket
import subprocess
import ctypes
from datetime import datetime, timedelta

# Графические библиотеки для трея
try:
    import pystray
    from pystray import MenuItem as item
    from PIL import Image, ImageDraw
    GUI_LIBS_AVAILABLE = True
except ImportError:
    GUI_LIBS_AVAILABLE = False

# Экспорт в Excel
try:
    import openpyxl
    from openpyxl.styles import Font, PatternFill, Alignment, Border, Side
    EXCEL_AVAILABLE = True
except ImportError:
    EXCEL_AVAILABLE = False

# PostgreSQL
try:
    import psycopg2
    PG_AVAILABLE = True
except ImportError:
    PG_AVAILABLE = False

# Google Sheets API
try:
    import gspread
    from google.oauth2.service_account import Credentials
    GSHEETS_AVAILABLE = True
except ImportError:
    GSHEETS_AVAILABLE = False

# ==========================================
# ЯДРО СИСТЕМЫ: Глобальные пути (STEALTH MODE)
# ==========================================
BASE_DIR = r"C:\RDP_Monitor"

try:
    os.makedirs(BASE_DIR, exist_ok=True)
except PermissionError:
    print(f"КРИТИЧЕСКАЯ ОШИБКА: Нет прав для создания папки {BASE_DIR}. Запустите скрипт от имени Администратора.")
    sys.exit(1)

CONFIG_FILE = os.path.join(BASE_DIR, 'rdp_monitor_config.json')
DB_FILE = os.path.join(BASE_DIR, 'rdp_sessions_history.db')
DEFAULT_CREDS_FILE = os.path.join(BASE_DIR, 'google_creds.json')
TASK_NAME = "RDP_Monitor_System_Service"

# Настройка логирования исключительно в оперативную память (Без создания файлов на диске)
logging.basicConfig(
    level=logging.INFO, 
    format='%(asctime)s - %(message)s',
    handlers=[
        logging.StreamHandler(sys.stdout)
    ]
)

def resource_path(relative_path):
    try: base_path = sys._MEIPASS
    except Exception: base_path = os.path.abspath(".")
    return os.path.join(base_path, relative_path)

def is_admin():
    try: return ctypes.windll.shell32.IsUserAnAdmin()
    except: return False

# ==========================================
# ОБЩИЕ ФУНКЦИИ WINDOWS API
# ==========================================
def get_client_info(session_id):
    ip_address = ""
    client_name = ""
    try:
        addr_info = win32ts.WTSQuerySessionInformation(0, session_id, win32ts.WTSClientAddress)
        if addr_info[0] == 2: 
            extracted_ip = f"{addr_info[1][2]}.{addr_info[1][3]}.{addr_info[1][4]}.{addr_info[1][5]}"
            if extracted_ip != "0.0.0.0": ip_address = extracted_ip
    except Exception: pass

    try:
        c_name = win32ts.WTSQuerySessionInformation(0, session_id, win32ts.WTSClientName)
        if c_name and c_name != "Console": client_name = c_name
    except Exception: pass

    if ip_address and client_name: return f"{ip_address} ({client_name})"
    elif client_name: return f"Шлюз/NAT ({client_name})"
    elif ip_address: return ip_address
    return "Локально/Неизвестно"

def get_active_sessions():
    try:
        sessions = win32ts.WTSEnumerateSessions(0, 1, 0)
        current = {}
        for s in sessions:
            try: user = win32ts.WTSQuerySessionInformation(0, s['SessionId'], win32ts.WTSUserName)
            except: user = ""
            if user:
                current[s['SessionId']] = {
                    'username': user, 'state': s['State'], 'client_info': get_client_info(s['SessionId'])
                }
        return current
    except Exception as e:
        logging.error(f"Ошибка WTS API: {e}")
        return {}

def send_telegram_notifications(config, text):
    token = config.get('bot_token')
    if not token: return
    url = f"https://api.telegram.org/bot{token}/sendMessage"
    for chat in config.get('telegram_chats', []):
        if chat.get('active'):
            try: requests.post(url, json={"chat_id": chat.get('id'), "text": text, "parse_mode": "HTML"}, timeout=5)
            except Exception as e: logging.error(f"Ошибка Telegram ({chat.get('name')}): {e}")

# ==========================================
# МЕНЕДЖЕР БАЗ ДАННЫХ
# ==========================================
class DatabaseManager:
    def __init__(self, config):
        self.config = config
        self.db_type = config.get('db_type', 'sqlite')
        self.pg_settings = config.get('pg_settings', {})
        self.gs_settings = config.get('gs_settings', {})
        self.sqlite_file = DB_FILE 
        self._gs_client = None

    def _get_connection(self):
        if self.db_type == 'postgres' and PG_AVAILABLE:
            return psycopg2.connect(
                host=self.pg_settings.get('host', 'localhost'), port=self.pg_settings.get('port', 5432),
                database=self.pg_settings.get('dbname', 'postgres'), user=self.pg_settings.get('user', 'postgres'),
                password=self.pg_settings.get('password', '')
            )
        else: return sqlite3.connect(self.sqlite_file)

    def _get_gsheet(self):
        if not GSHEETS_AVAILABLE: raise ImportError("Библиотеки gspread не установлены.")
        creds_path = self.gs_settings.get('creds_path', DEFAULT_CREDS_FILE)
        if not os.path.isabs(creds_path): creds_path = os.path.join(BASE_DIR, os.path.basename(creds_path))
        if not os.path.exists(creds_path): raise FileNotFoundError(f"Файл ключей {creds_path} не найден.")

        if not self._gs_client:
            scopes = ["https://www.googleapis.com/auth/spreadsheets", "https://www.googleapis.com/auth/drive"]
            credentials = Credentials.from_service_account_file(creds_path, scopes=scopes)
            self._gs_client = gspread.authorize(credentials)
        sheet_url = self.gs_settings.get('sheet_url', '')
        return self._gs_client.open_by_url(sheet_url).sheet1

    def init_db(self):
        try:
            if self.db_type == 'gsheets':
                if not GSHEETS_AVAILABLE: return
                sheet = self._get_gsheet()
                if not sheet.row_values(1): sheet.append_row(["Timestamp", "Server Name", "Username", "Session ID", "Event Type", "IP Address"])
                return

            with self._get_connection() as conn:
                cursor = conn.cursor()
                if self.db_type == 'postgres' and PG_AVAILABLE:
                    cursor.execute('''CREATE TABLE IF NOT EXISTS session_logs (id SERIAL PRIMARY KEY, timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP, server_name TEXT, username TEXT, session_id INTEGER, event_type TEXT, ip_address TEXT)''')
                else:
                    cursor.execute('''CREATE TABLE IF NOT EXISTS session_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, timestamp DATETIME DEFAULT CURRENT_TIMESTAMP, server_name TEXT DEFAULT 'Unknown', username TEXT, session_id INTEGER, event_type TEXT, ip_address TEXT)''')
                    cursor.execute("PRAGMA table_info(session_logs)")
                    columns = [info[1] for info in cursor.fetchall()]
                    if 'server_name' not in columns: cursor.execute("ALTER TABLE session_logs ADD COLUMN server_name TEXT DEFAULT 'Unknown'")
                conn.commit()
        except Exception as e: logging.error(f"Ошибка инициализации БД: {e}")

    def log_event(self, server_name, username, session_id, event_type, ip_address="Unknown"):
        timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        if self.db_type == 'gsheets':
            try: self._get_gsheet().append_row([timestamp, server_name, username, session_id, event_type, ip_address])
            except Exception as e: logging.error(f"Ошибка GSheets: {e}")
            return
        query_pg = '''INSERT INTO session_logs (server_name, username, session_id, event_type, ip_address) VALUES (%s, %s, %s, %s, %s)'''
        query_sq = '''INSERT INTO session_logs (server_name, username, session_id, event_type, ip_address) VALUES (?, ?, ?, ?, ?)'''
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                if self.db_type == 'postgres' and PG_AVAILABLE: cursor.execute(query_pg, (server_name, username, session_id, event_type, ip_address))
                else: cursor.execute(query_sq, (server_name, username, session_id, event_type, ip_address))
                conn.commit()
        except Exception as e: logging.error(f"Ошибка записи в БД: {e}")

    def cleanup_old_records(self, retention_days):
        if retention_days <= 0 or self.db_type == 'gsheets': return 0
        cutoff_str = (datetime.now() - timedelta(days=retention_days)).strftime('%Y-%m-%d %H:%M:%S')
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                if self.db_type == 'postgres' and PG_AVAILABLE: cursor.execute('DELETE FROM session_logs WHERE timestamp < %s', (cutoff_str,))
                else: cursor.execute('DELETE FROM session_logs WHERE timestamp < ?', (cutoff_str,))
                deleted_count = cursor.rowcount
                conn.commit()
                return deleted_count
        except Exception: return 0

    def get_logs(self, limit=5000, username_filter=""):
        if self.db_type == 'gsheets':
            try:
                data = self._get_gsheet().get_all_values()
                if len(data) <= 1: return []
                formatted_rows = []
                for row in data[1:]:
                    row = row + [""] * (6 - len(row))
                    if username_filter and username_filter.lower() not in row[2].lower(): continue
                    formatted_rows.append(tuple(row))
                formatted_rows.reverse()
                return formatted_rows[:limit]
            except Exception: return []
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                query = 'SELECT timestamp, server_name, username, session_id, event_type, ip_address FROM session_logs'
                params = []
                if username_filter:
                    if self.db_type == 'postgres' and PG_AVAILABLE:
                        query += ' WHERE username ILIKE %s'; params.append(f'%{username_filter}%')
                    else:
                        query += ' WHERE username LIKE ?'; params.append(f'%{username_filter}%')
                query += f' ORDER BY timestamp DESC LIMIT {limit}'
                cursor.execute(query, tuple(params))
                return cursor.fetchall()
        except Exception: return []

# ==========================================
# ГРАФИЧЕСКОЕ ПРИЛОЖЕНИЕ (GUI РЕЖИМ)
# ==========================================
class RDPMonitorApp:
    def __init__(self, root):
        self.root = root
        self.root.title("RDP Monitor Pro - Enterprise Edition 5.8 (Stealth Mode)")
        self.root.geometry("1000x700")
        self.root.protocol('WM_DELETE_WINDOW', self.hide_window)

        self.icon_path = resource_path('icon.ico')
        if os.path.exists(self.icon_path):
            try: self.root.iconbitmap(self.icon_path)
            except Exception: pass

        self.monitor_thread = None
        self.stop_event = threading.Event()
        self.known_sessions = {}
        self.tray_icon = None
        
        self.config = self._load_config_data()
        self.db_manager = DatabaseManager(self.config)
        self.telegram_targets_vars = []
        self.is_admin_user = is_admin()

        self._build_gui()
        self.apply_config_to_gui()
        threading.Thread(target=self.db_manager.init_db, daemon=True).start()

    def _load_config_data(self):
        default_config = {
            'server_name': socket.gethostname(),
            'bot_token': '', 'telegram_chats': [], 'db_type': 'sqlite',
            'pg_settings': {'host': 'localhost', 'port': 5432, 'dbname': 'postgres', 'user': 'postgres', 'password': ''},
            'gs_settings': {'sheet_url': '', 'creds_path': DEFAULT_CREDS_FILE},
            'retention_days': 7
        }
        if os.path.exists(CONFIG_FILE):
            try:
                with open(CONFIG_FILE, 'r', encoding='utf-8') as f: default_config.update(json.load(f))
            except Exception: pass
        return default_config

    def _save_config_data(self):
        try:
            with open(CONFIG_FILE, 'w', encoding='utf-8') as f: json.dump(self.config, f, indent=4)
        except Exception as e: logging.error(f"Ошибка сохранения конфига: {e}")

    def _build_gui(self):
        self.notebook = ttk.Notebook(self.root)
        self.notebook.pack(fill=tk.BOTH, expand=True, padx=5, pady=5)

        self.tab_live = ttk.Frame(self.notebook)
        self.tab_journal = ttk.Frame(self.notebook)
        self.tab_settings = ttk.Frame(self.notebook)

        self.notebook.add(self.tab_live, text='📊 Панель управления')
        self.notebook.add(self.tab_journal, text='📖 База Данных (Журнал)')
        self.notebook.add(self.tab_settings, text='⚙️ Настройки и Служба')

        self._build_tab_live()
        self._build_tab_journal()
        self._build_tab_settings()

    def _build_tab_live(self):
        info_frame = ttk.LabelFrame(self.tab_live, text="Информация", padding=(10, 10))
        info_frame.pack(fill=tk.X, padx=10, pady=10)
        ttk.Label(info_frame, text="ВНИМАНИЕ: Основной мониторинг должен выполняться системной службой.\nЗдесь вы можете запустить временный тест для проверки корректности настроек.", foreground="blue", justify=tk.LEFT).pack(anchor=tk.W)

        control_frame = ttk.LabelFrame(self.tab_live, text="Локальный тестовый мониторинг", padding=(10, 10))
        control_frame.pack(fill=tk.X, padx=10, pady=5)
        self.start_btn = ttk.Button(control_frame, text="▶ Запустить (Тест)", command=self.start_monitoring)
        self.start_btn.pack(side=tk.LEFT, padx=(0, 10))
        self.stop_btn = ttk.Button(control_frame, text="⏹ Остановить", command=self.stop_monitoring, state=tk.DISABLED)
        self.stop_btn.pack(side=tk.LEFT)
        self.status_label = ttk.Label(control_frame, text="Готов к тестированию", font=("Arial", 10, "bold"))
        self.status_label.pack(side=tk.RIGHT, padx=10)

        log_frame = ttk.LabelFrame(self.tab_live, text="Консоль событий (Local Live)", padding=(10, 10))
        log_frame.pack(fill=tk.BOTH, expand=True, padx=10, pady=5)
        self.log_area = scrolledtext.ScrolledText(log_frame, state='disabled', wrap=tk.WORD, font=("Consolas", 9))
        self.log_area.pack(fill=tk.BOTH, expand=True)

    def _build_tab_journal(self):
        filter_frame = ttk.LabelFrame(self.tab_journal, text="Фильтрация, поиск и экспорт", padding=(10, 5))
        filter_frame.pack(fill=tk.X, padx=10, pady=5)

        ttk.Label(filter_frame, text="Логин:").pack(side=tk.LEFT, padx=(0, 5))
        self.search_entry = ttk.Entry(filter_frame, width=20)
        self.search_entry.pack(side=tk.LEFT, padx=(0, 10))
        ttk.Button(filter_frame, text="🔍 Применить", command=self.refresh_db_view_threaded).pack(side=tk.LEFT, padx=(0, 20))
        
        export_state = tk.NORMAL if EXCEL_AVAILABLE else tk.DISABLED
        self.export_btn = ttk.Button(filter_frame, text="📊 Экспорт в Excel" if EXCEL_AVAILABLE else "❌ Нет openpyxl", command=self.export_to_excel, state=export_state)
        self.export_btn.pack(side=tk.LEFT)

        columns = ('timestamp', 'server', 'user', 'session', 'event', 'ip')
        self.tree = ttk.Treeview(self.tab_journal, columns=columns, show='headings', selectmode='browse')
        headings = {'timestamp': 'Время', 'server': 'Сервер', 'user': 'Пользователь', 'session': 'ID', 'event': 'Событие', 'ip': 'ПК / IP Адрес'}
        for col, title in headings.items():
            self.tree.heading(col, text=title, command=lambda _col=col: self.treeview_sort_column(self.tree, _col, False))
            self.tree.column(col, anchor=tk.W)

        self.tree.column('timestamp', width=130); self.tree.column('server', width=100)
        self.tree.column('user', width=120); self.tree.column('session', width=40, anchor=tk.CENTER)
        self.tree.column('event', width=200); self.tree.column('ip', width=180) 

        scrollbar = ttk.Scrollbar(self.tab_journal, orient=tk.VERTICAL, command=self.tree.yview)
        self.tree.configure(yscroll=scrollbar.set)
        self.tree.pack(side=tk.LEFT, fill=tk.BOTH, expand=True, padx=(10, 0), pady=10)
        scrollbar.pack(side=tk.RIGHT, fill=tk.Y, padx=(0, 10), pady=10)

    def _build_tab_settings(self):
        canvas = tk.Canvas(self.tab_settings)
        scrollbar = ttk.Scrollbar(self.tab_settings, orient="vertical", command=canvas.yview)
        scrollable_frame = ttk.Frame(canvas)

        scrollable_frame.bind("<Configure>", lambda e: canvas.configure(scrollregion=canvas.bbox("all")))
        canvas.create_window((0, 0), window=scrollable_frame, anchor="nw")
        canvas.configure(yscrollcommand=scrollbar.set)
        canvas.pack(side="left", fill="both", expand=True)
        scrollbar.pack(side="right", fill="y")

        # --- БЛОК УПРАВЛЕНИЯ СЛУЖБОЙ ---
        svc_frame = ttk.LabelFrame(scrollable_frame, text="Управление системной службой (Windows Task Scheduler)", padding=(10, 10))
        svc_frame.pack(fill=tk.X, padx=10, pady=5, expand=True)
        
        if not self.is_admin_user:
            ttk.Label(svc_frame, text="⚠️ ВНИМАНИЕ: Для управления службой запустите программу от имени Администратора!", foreground="red", font=("", 9, "bold")).pack(anchor=tk.W, pady=(0, 10))

        btn_container = ttk.Frame(svc_frame)
        btn_container.pack(fill=tk.X)
        
        btn_state = tk.NORMAL if self.is_admin_user else tk.DISABLED

        self.btn_install = ttk.Button(btn_container, text="⚙️ Создать службу", command=self.install_service, state=btn_state)
        self.btn_install.pack(side=tk.LEFT, padx=5)
        
        self.btn_start = ttk.Button(btn_container, text="▶ Запустить службу", command=self.start_sys_service, state=btn_state)
        self.btn_start.pack(side=tk.LEFT, padx=5)
        
        self.btn_stop = ttk.Button(btn_container, text="⏹ Остановить службу", command=self.stop_sys_service, state=btn_state)
        self.btn_stop.pack(side=tk.LEFT, padx=5)
        
        self.btn_remove = ttk.Button(btn_container, text="❌ Удалить службу", command=self.remove_service, state=btn_state)
        self.btn_remove.pack(side=tk.LEFT, padx=5)
        
        self.btn_status = ttk.Button(btn_container, text="🔄 Проверить статус", command=self.check_service_status)
        self.btn_status.pack(side=tk.RIGHT, padx=5)

        self.svc_status_lbl = ttk.Label(svc_frame, text="Статус службы: Неизвестно", font=("Arial", 10, "bold"))
        self.svc_status_lbl.pack(anchor=tk.W, pady=(10, 0))

        # --- ОСНОВНЫЕ НАСТРОЙКИ ---
        core_frame = ttk.LabelFrame(scrollable_frame, text="Основная конфигурация", padding=(10, 10))
        core_frame.pack(fill=tk.X, padx=10, pady=5, expand=True)

        ttk.Label(core_frame, text="Имя сервера:").grid(row=0, column=0, sticky=tk.W, pady=2)
        self.server_name_entry = ttk.Entry(core_frame, width=40)
        self.server_name_entry.grid(row=0, column=1, sticky=tk.W, padx=5, pady=2)

        ttk.Label(core_frame, text="Хранить логи (дней):").grid(row=1, column=0, sticky=tk.W, pady=2)
        self.retention_entry = ttk.Spinbox(core_frame, from_=0, to=365, width=10)
        self.retention_entry.grid(row=1, column=1, sticky=tk.W, padx=5, pady=2)

        db_frame = ttk.LabelFrame(scrollable_frame, text="База данных", padding=(10, 10))
        db_frame.pack(fill=tk.X, padx=10, pady=5, expand=True)

        self.db_type_var = tk.StringVar(value="sqlite")
        ttk.Radiobutton(db_frame, text="Локальная (SQLite)", variable=self.db_type_var, value="sqlite", command=self._toggle_db_fields).grid(row=0, column=0, sticky=tk.W)
        ttk.Radiobutton(db_frame, text="Внешняя (PostgreSQL)", variable=self.db_type_var, value="postgres", command=self._toggle_db_fields).grid(row=0, column=1, sticky=tk.W, padx=10)
        ttk.Radiobutton(db_frame, text="Облачная (Google Sheets)", variable=self.db_type_var, value="gsheets", command=self._toggle_db_fields).grid(row=0, column=2, sticky=tk.W, padx=10)

        self.pg_container = ttk.Frame(db_frame)
        self.pg_container.grid(row=1, column=0, columnspan=3, pady=10, sticky=tk.W)
        self.pg_entries = {}
        for i, (lbl_text, key) in enumerate([("Хост:", "host"), ("Порт:", "port"), ("База:", "dbname"), ("Пользователь:", "user"), ("Пароль:", "password")]):
            ttk.Label(self.pg_container, text=lbl_text).grid(row=i, column=0, sticky=tk.W, pady=2)
            ent = ttk.Entry(self.pg_container, width=25, show="*" if key == "password" else "")
            ent.grid(row=i, column=1, padx=5, pady=2)
            self.pg_entries[key] = ent

        self.gs_container = ttk.Frame(db_frame)
        self.gs_container.grid(row=2, column=0, columnspan=3, pady=10, sticky=tk.W)
        ttk.Label(self.gs_container, text="URL Таблицы:").grid(row=0, column=0, sticky=tk.W, pady=2)
        self.gs_url_entry = ttk.Entry(self.gs_container, width=60)
        self.gs_url_entry.grid(row=0, column=1, padx=5, pady=2)
        ttk.Label(self.gs_container, text="Путь к JSON ключу:").grid(row=1, column=0, sticky=tk.W, pady=2)
        self.gs_creds_entry = ttk.Entry(self.gs_container, width=45)
        self.gs_creds_entry.grid(row=1, column=1, sticky=tk.W, padx=5, pady=2)
        ttk.Button(self.gs_container, text="Обзор...", command=self._browse_json_key).grid(row=1, column=1, sticky=tk.E, padx=5)

        tg_frame = ttk.LabelFrame(scrollable_frame, text="Уведомления Telegram", padding=(10, 10))
        tg_frame.pack(fill=tk.BOTH, padx=10, pady=5, expand=True)

        ttk.Label(tg_frame, text="Bot Token:").grid(row=0, column=0, sticky=tk.W, pady=2)
        self.token_entry = ttk.Entry(tg_frame, width=50)
        self.token_entry.grid(row=0, column=1, columnspan=2, sticky=tk.W, padx=5, pady=2)

        self.targets_container = ttk.Frame(tg_frame)
        self.targets_container.grid(row=2, column=0, columnspan=3, sticky=tk.W, pady=(10,0))
        self.add_target_btn = ttk.Button(tg_frame, text="➕ Добавить получателя", command=self._add_target_row)
        self.add_target_btn.grid(row=3, column=0, pady=10, sticky=tk.W)

        save_btn = ttk.Button(scrollable_frame, text="💾 Сохранить настройки", command=self.save_settings)
        save_btn.pack(pady=15)
        
        self.root.after(1000, self.check_service_status)

    def _run_cmd(self, command):
        creation_flags = 0x08000000 
        result = subprocess.run(command, capture_output=True, text=True, creationflags=creation_flags)
        
        # Исправленный блок: безопасное получение вывода без ошибок NoneType
        out = (result.stdout or "").strip()
        err = (result.stderr or "").strip()
        
        return result.returncode, out, err

    def _get_execution_command(self):
        if getattr(sys, 'frozen', False):
            return f'\\"{sys.executable}\\" --service'
        else:
            return f'\\"{sys.executable}\\" \\"{os.path.abspath(sys.argv[0])}\\" --service'

    def install_service(self):
        cmd = self._get_execution_command()
        install_cmd = f'schtasks /create /tn "{TASK_NAME}" /tr "{cmd}" /sc onstart /ru SYSTEM /rl HIGHEST /f'
        code, out, err = self._run_cmd(install_cmd)
        if code == 0:
            messagebox.showinfo("Успех", "Системная служба успешно установлена.\nОна будет запускаться автоматически при старте ОС.")
            self.check_service_status()
        else:
            error_msg = err if err else out
            messagebox.showerror("Ошибка", f"Не удалось создать службу:\n{error_msg}")

    def remove_service(self):
        if messagebox.askyesno("Подтверждение", "Удалить системную службу мониторинга?"):
            code, out, err = self._run_cmd(f'schtasks /delete /tn "{TASK_NAME}" /f')
            if code == 0:
                messagebox.showinfo("Успех", "Системная служба удалена.")
                self.check_service_status()
            else:
                error_msg = err if err else out
                messagebox.showerror("Ошибка", f"Не удалось удалить службу:\n{error_msg}")

    def start_sys_service(self):
        code, out, err = self._run_cmd(f'schtasks /run /tn "{TASK_NAME}"')
        if code == 0:
            self.svc_status_lbl.config(text="Статус службы: Запускается...", foreground="orange")
            self.root.after(2000, self.check_service_status)
        else:
            error_msg = err if err else out
            messagebox.showerror("Ошибка", f"Не удалось запустить службу:\n{error_msg}")

    def stop_sys_service(self):
        code, out, err = self._run_cmd(f'schtasks /end /tn "{TASK_NAME}"')
        if code == 0:
            self.check_service_status()
        else:
            error_msg = err if err else out
            messagebox.showerror("Ошибка", f"Не удалось остановить службу:\n{error_msg}")

    def check_service_status(self):
        code, out, err = self._run_cmd(f'schtasks /query /tn "{TASK_NAME}" /fo LIST')
        if code != 0:
            self.svc_status_lbl.config(text="Статус службы: Не установлена", foreground="red")
            return

        if "Running" in out or "Работает" in out:
            self.svc_status_lbl.config(text="Статус службы: АКТИВНА (Работает в фоне)", foreground="green")
        elif "Ready" in out or "Готов" in out:
            self.svc_status_lbl.config(text="Статус службы: ОСТАНОВЛЕНА (Готова к запуску)", foreground="blue")
        else:
            self.svc_status_lbl.config(text="Статус службы: Установлена", foreground="black")

    def apply_config_to_gui(self):
        self.server_name_entry.delete(0, tk.END); self.server_name_entry.insert(0, self.config.get('server_name', ''))
        self.retention_entry.delete(0, tk.END); self.retention_entry.insert(0, str(self.config.get('retention_days', 7)))
        self.token_entry.delete(0, tk.END); self.token_entry.insert(0, self.config.get('bot_token', ''))
        self.db_type_var.set(self.config.get('db_type', 'sqlite'))
        
        for key, ent in self.pg_entries.items():
            ent.delete(0, tk.END); ent.insert(0, str(self.config.get('pg_settings', {}).get(key, '')))
            
        self.gs_url_entry.delete(0, tk.END); self.gs_url_entry.insert(0, self.config.get('gs_settings', {}).get('sheet_url', ''))
        self.gs_creds_entry.delete(0, tk.END); self.gs_creds_entry.insert(0, self.config.get('gs_settings', {}).get('creds_path', DEFAULT_CREDS_FILE))
        
        self._toggle_db_fields()

        for row in self.telegram_targets_vars[:]: self._remove_target_row(row)
        for chat in self.config.get('telegram_chats', []): self._add_target_row(chat.get('active', True), chat.get('name', ''), chat.get('id', ''))
        if not self.config.get('telegram_chats'): self._add_target_row()

    def _browse_json_key(self):
        file_path = filedialog.askopenfilename(title="Выберите JSON ключ", filetypes=[("JSON Files", "*.json")])
        if file_path: self.gs_creds_entry.delete(0, tk.END); self.gs_creds_entry.insert(0, file_path)

    def _toggle_db_fields(self):
        db_type = self.db_type_var.get()
        pg_state = 'normal' if db_type == 'postgres' else 'disabled'
        for ent in self.pg_entries.values(): ent.config(state=pg_state)
        gs_state = 'normal' if db_type == 'gsheets' else 'disabled'
        self.gs_url_entry.config(state=gs_state); self.gs_creds_entry.config(state=gs_state)

    def _add_target_row(self, active=True, name="", chat_id=""):
        row = len(self.telegram_targets_vars) + 1
        var_active, var_name, var_id = tk.BooleanVar(value=active), tk.StringVar(value=name), tk.StringVar(value=chat_id)
        
        chk = ttk.Checkbutton(self.targets_container, variable=var_active)
        chk.grid(row=row, column=0, padx=5, pady=2)
        ent_name = ttk.Entry(self.targets_container, textvariable=var_name, width=25)
        ent_name.grid(row=row, column=1, padx=5, pady=2)
        ent_id = ttk.Entry(self.targets_container, textvariable=var_id, width=20)
        ent_id.grid(row=row, column=2, padx=5, pady=2)
        
        btn_del = ttk.Button(self.targets_container, text="❌", width=3)
        row_data = {'active': var_active, 'name': var_name, 'id': var_id, 'widgets': (chk, ent_name, ent_id, btn_del)}
        btn_del.config(command=lambda: self._remove_target_row(row_data))
        btn_del.grid(row=row, column=3, padx=5, pady=2)
        self.telegram_targets_vars.append(row_data)

    def _remove_target_row(self, row_data):
        for widget in row_data['widgets']: widget.destroy()
        self.telegram_targets_vars.remove(row_data)

    def save_settings(self):
        if not self.is_admin_user:
            messagebox.showerror("Ошибка доступа", "Настройки можно изменять только запустив программу от имени Администратора.")
            return

        self.config['server_name'] = self.server_name_entry.get().strip()
        self.config['bot_token'] = self.token_entry.get().strip()
        self.config['db_type'] = self.db_type_var.get()
        try: self.config['retention_days'] = int(self.retention_entry.get() or 0)
        except ValueError: self.config['retention_days'] = 7
        
        self.config['pg_settings'] = {key: ent.get().strip() for key, ent in self.pg_entries.items()}
        self.config['gs_settings'] = {'sheet_url': self.gs_url_entry.get().strip(), 'creds_path': self.gs_creds_entry.get().strip()}
        self.config['telegram_chats'] = [{'active': r['active'].get(), 'name': r['name'].get().strip(), 'id': r['id'].get().strip()} for r in self.telegram_targets_vars if r['id'].get().strip()]
        
        try:
            self._save_config_data()
            self.db_manager = DatabaseManager(self.config)
            threading.Thread(target=self.db_manager.init_db, daemon=True).start()
            self.refresh_db_view_threaded()
            
            code, out, err = self._run_cmd(f'schtasks /query /tn "{TASK_NAME}" /fo LIST')
            if "Running" in out or "Работает" in out:
                self.stop_sys_service()
                time.sleep(1)
                self.start_sys_service()
                messagebox.showinfo("Успех", "Настройки сохранены. Служба автоматически перезапущена.")
            else:
                messagebox.showinfo("Успех", "Настройки успешно сохранены.")
        except Exception as e:
            messagebox.showerror("Ошибка", f"Сбой при сохранении: {e}")

    def refresh_db_view_threaded(self):
        threading.Thread(target=self._refresh_db_view, daemon=True).start()

    def _refresh_db_view(self):
        filter_text = self.search_entry.get().strip()
        logs = self.db_manager.get_logs(username_filter=filter_text)
        self.root.after(0, self._render_db_view, logs)

    def _render_db_view(self, logs):
        for row in self.tree.get_children(): self.tree.delete(row)
        for record in logs: self.tree.insert('', tk.END, values=record)

    def treeview_sort_column(self, tv, col, reverse):
        l = [(tv.set(k, col), k) for k in tv.get_children('')]
        try: l.sort(key=lambda t: int(t[0]), reverse=reverse)
        except ValueError: l.sort(reverse=reverse)
        for index, (val, k) in enumerate(l): tv.move(k, '', index)
        tv.heading(col, command=lambda: self.treeview_sort_column(tv, col, not reverse))

    def export_to_excel(self):
        if not EXCEL_AVAILABLE: return
        items = self.tree.get_children()
        if not items: return messagebox.showwarning("Пусто", "Нет данных.")
        file_path = filedialog.asksaveasfilename(defaultextension=".xlsx", filetypes=[("Excel", "*.xlsx")])
        if not file_path: return 
        try:
            wb = openpyxl.Workbook()
            ws = wb.active
            ws.append(["Время", "Сервер", "Пользователь", "ID Сессии", "Событие", "IP Адрес"])
            for item_id in items: ws.append(self.tree.item(item_id)['values'])
            wb.save(file_path)
            messagebox.showinfo("Успех", "Отчет сохранен.")
        except Exception as e: messagebox.showerror("Ошибка", str(e))

    def create_tray_icon_image(self):
        if os.path.exists(self.icon_path):
            try: return Image.open(self.icon_path)
            except Exception: pass
        image = Image.new('RGB', (64, 64), (0, 120, 215))
        ImageDraw.Draw(image).rectangle((16, 16, 48, 48), outline=(255, 255, 255), width=4)
        return image

    def hide_window(self):
        self.root.withdraw()
        if GUI_LIBS_AVAILABLE:
            menu = pystray.Menu(item('Развернуть', self.show_window, default=True), item('Выход', self.quit_application))
            self.tray_icon = pystray.Icon("RDPMonitor", self.create_tray_icon_image(), "RDP Monitor", menu)
            threading.Thread(target=self.tray_icon.run, daemon=True).start()

    def show_window(self, icon, item):
        if self.tray_icon: self.tray_icon.stop()
        self.root.after(0, self.root.deiconify)

    def quit_application(self, icon=None, item=None):
        if self.tray_icon: self.tray_icon.stop()
        self.stop_monitoring()
        self.root.after(0, self.root.destroy)

    def log_message(self, message):
        def append_text():
            self.log_area.config(state='normal')
            self.log_area.insert(tk.END, f"{time.strftime('%H:%M:%S')} | {message}\n")
            self.log_area.see(tk.END)
            self.log_area.config(state='disabled')
        self.root.after(0, append_text)

    def process_event(self, event_title, user, sid, client_info, icon_emoji):
        server_name = self.config.get('server_name', 'Unknown')
        self.db_manager.log_event(server_name, user, sid, event_title, client_info)
        self.log_message(f"[{event_title}] {user} (ID: {sid})")
        msg = (f"{icon_emoji} <b>{event_title}</b>\n🖥 Сервер: <code>{server_name}</code>\n👤 Пользователь: <code>{user}</code>\n💻 Сессия ID: {sid}\n🌐 Узел: {client_info}")
        send_telegram_notifications(self.config, msg)
        self.refresh_db_view_threaded()

    def monitor_logic(self):
        self.known_sessions = get_active_sessions()
        self.log_message("Сбор данных (Локальный тест) запущен.")
        while not self.stop_event.is_set():
            time.sleep(3)
            current_sessions = get_active_sessions()
            for sid, info in current_sessions.items():
                user, state, c_info = info['username'], info['state'], info['client_info']
                if sid not in self.known_sessions:
                    if state == 0: self.process_event("Подключение к RDP", user, sid, c_info, "🟢")
                else:
                    old_state = self.known_sessions[sid]['state']
                    if old_state != 0 and state == 0: self.process_event("Переподключение к RDP", user, sid, c_info, "🔄")
                    elif old_state == 0 and state != 0: self.process_event("Отключение от RDP (Свернуто)", user, sid, c_info, "⏸")
            for sid, info in self.known_sessions.items():
                if sid not in current_sessions:
                    self.process_event("Завершение RDP-сеанса (Выход)", info['username'], sid, info['client_info'], "🔴")
            self.known_sessions = current_sessions

    def start_monitoring(self):
        self.start_btn.config(state=tk.DISABLED); self.stop_btn.config(state=tk.NORMAL)
        self.status_label.config(text="Тест активен", foreground="orange")
        self.stop_event.clear()
        self.monitor_thread = threading.Thread(target=self.monitor_logic, daemon=True)
        self.monitor_thread.start()

    def stop_monitoring(self):
        self.stop_event.set()
        self.start_btn.config(state=tk.NORMAL); self.stop_btn.config(state=tk.DISABLED)
        self.status_label.config(text="Тест остановлен", foreground="green")

# ==========================================
# ФОНОВЫЙ РЕЖИМ СЛУЖБЫ (HEADLESS MODE)
# ==========================================
def run_as_service():
    # --- БЛОК ЗАЩИТЫ ОТ ДУБЛИКАТОВ (MUTEX) ---
    mutex_name = "Global\\RDPMonitorEnterpriseService"
    mutex = ctypes.windll.kernel32.CreateMutexW(None, False, mutex_name)
    last_error = ctypes.windll.kernel32.GetLastError()
    
    # 183 = ERROR_ALREADY_EXISTS (Процесс службы уже запущен)
    if last_error == 183:
        logging.error("КРИТИЧЕСКИЙ СБОЙ: Попытка запустить дубликат службы. Процесс прерван.")
        sys.exit(0)
    # --- КОНЕЦ БЛОКА ЗАЩИТЫ ---

    logging.info("=== Инициализация фоновой службы RDP Monitor ===")
    config = {}
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE, 'r', encoding='utf-8') as f: config = json.load(f)
        except Exception as e: logging.error(f"Ошибка чтения конфига: {e}")
            
    if not config: config = {'server_name': socket.gethostname(), 'db_type': 'sqlite'}
        
    db_manager = DatabaseManager(config)
    db_manager.init_db()
    
    try:
        deleted = db_manager.cleanup_old_records(int(config.get('retention_days', 7)))
        if deleted > 0: logging.info(f"Очистка БД: удалено {deleted} записей.")
    except Exception as e: logging.error(f"Ошибка очистки БД: {e}")

    server_name = config.get('server_name', 'Unknown')
    known_sessions = get_active_sessions()
    logging.info(f"Служба успешно стартовала на узле {server_name}. Мониторинг активен.")

    def process_headless_event(event_title, user, sid, client_info, icon_emoji):
        db_manager.log_event(server_name, user, sid, event_title, client_info)
        logging.info(f"[{event_title}] {user} (ID: {sid}, Клиент: {client_info})")
        msg = (f"{icon_emoji} <b>{event_title}</b>\n🖥 Сервер: <code>{server_name}</code>\n👤 Пользователь: <code>{user}</code>\n💻 Сессия ID: {sid}\n🌐 Узел: {client_info}")
        send_telegram_notifications(config, msg)

    try:
        while True:
            time.sleep(3)
            current_sessions = get_active_sessions()
            for sid, info in current_sessions.items():
                user, state, c_info = info['username'], info['state'], info['client_info']
                if sid not in known_sessions:
                    if state == 0: process_headless_event("Подключение к RDP", user, sid, c_info, "🟢")
                else:
                    old_state = known_sessions[sid]['state']
                    if old_state != 0 and state == 0: process_headless_event("Переподключение к RDP", user, sid, c_info, "🔄")
                    elif old_state == 0 and state != 0: process_headless_event("Отключение от RDP (Свернуто)", user, sid, c_info, "⏸")

            for sid, info in known_sessions.items():
                if sid not in current_sessions: process_headless_event("Завершение RDP-сеанса (Выход)", info['username'], sid, info['client_info'], "🔴")
            known_sessions = current_sessions
    except KeyboardInterrupt: logging.info("Работа службы прервана оператором.")
    except Exception as e: logging.critical(f"Критический сбой службы: {e}")

# ==========================================
# ТОЧКА ВХОДА
# ==========================================
if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == '--service':
        run_as_service()
    else:
        root = tk.Tk()
        app = RDPMonitorApp(root)
        root.mainloop()