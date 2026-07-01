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
TASK_NAME = "RDP_Monitor_System_Service"

# Настройка логирования в оперативную память
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

def log_error_to_file(message):
    try:
        os.makedirs(BASE_DIR, exist_ok=True)
        log_path = os.path.join(BASE_DIR, 'monitor_error.log')
        with open(log_path, 'a', encoding='utf-8') as f:
            f.write(f"{datetime.now().strftime('%Y-%m-%d %H:%M:%S')} - {message}\n")
    except Exception:
        pass

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
        self.sqlite_file = DB_FILE 

    def _get_connection(self):
        return sqlite3.connect(self.sqlite_file)

    def init_db(self):
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                cursor.execute('''CREATE TABLE IF NOT EXISTS session_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, 
                    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP, 
                    server_name TEXT DEFAULT 'Unknown', 
                    username TEXT, 
                    session_id INTEGER, 
                    event_type TEXT, 
                    ip_address TEXT
                )''')
                cursor.execute("PRAGMA table_info(session_logs)")
                columns = [info[1] for info in cursor.fetchall()]
                if 'server_name' not in columns: 
                    cursor.execute("ALTER TABLE session_logs ADD COLUMN server_name TEXT DEFAULT 'Unknown'")
                conn.commit()
        except Exception as e: logging.error(f"Ошибка инициализации БД: {e}")

    def log_event(self, server_name, username, session_id, event_type, ip_address="Unknown"):
        query_sq = '''INSERT INTO session_logs (server_name, username, session_id, event_type, ip_address) VALUES (?, ?, ?, ?, ?)'''
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                cursor.execute(query_sq, (server_name, username, session_id, event_type, ip_address))
                conn.commit()
        except Exception as e: logging.error(f"Ошибка записи в БД: {e}")

    def cleanup_old_records(self, retention_days):
        if retention_days <= 0: return 0
        cutoff_str = (datetime.now() - timedelta(days=retention_days)).strftime('%Y-%m-%d %H:%M:%S')
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                cursor.execute('DELETE FROM session_logs WHERE timestamp < ?', (cutoff_str,))
                deleted_count = cursor.rowcount
                conn.commit()
                return deleted_count
        except Exception: return 0

# ==========================================
# ГРАФИЧЕСКОЕ ПРИЛОЖЕНИЕ (GUI РЕЖИМ)
# ==========================================
class RDPMonitorApp:
    def __init__(self, root):
        self.root = root
        self.root.title("RDP Monitor Pro (Stealth Mode)")
        self.root.geometry("800x600")
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
        
        # Автоматическая проверка статуса службы
        self.root.after(1000, self.check_service_status)

    def _load_config_data(self):
        default_config = {
            'server_name': socket.gethostname(),
            'bot_token': '', 
            'telegram_chats': [], 
            'db_type': 'sqlite',
            'retention_days': 7,
            'crm_enabled': False,
            'crm_url': '',
            'crm_token': ''
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
        self.tab_settings = ttk.Frame(self.notebook)

        self.notebook.add(self.tab_live, text='📊 Панель управления')
        self.notebook.add(self.tab_settings, text='⚙️ Настройки')

        self._build_tab_live()
        self._build_tab_settings()

    def _build_tab_live(self):
        info_frame = ttk.LabelFrame(self.tab_live, text="Информация", padding=(10, 10))
        info_frame.pack(fill=tk.X, padx=10, pady=10)
        ttk.Label(info_frame, text="ВНИМАНИЕ: Основной мониторинг должен выполняться в фоновом режиме.\nЗдесь вы можете запустить временный тест для проверки корректности настроек.", foreground="blue", justify=tk.LEFT).pack(anchor=tk.W)

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

        # --- TELEGRAM ---
        tg_frame = ttk.LabelFrame(scrollable_frame, text="Уведомления Telegram", padding=(10, 10))
        tg_frame.pack(fill=tk.BOTH, padx=10, pady=5, expand=True)

        ttk.Label(tg_frame, text="Bot Token:").grid(row=0, column=0, sticky=tk.W, pady=2)
        self.token_entry = ttk.Entry(tg_frame, width=50)
        self.token_entry.grid(row=0, column=1, columnspan=2, sticky=tk.W, padx=5, pady=2)

        self.targets_container = ttk.Frame(tg_frame)
        self.targets_container.grid(row=2, column=0, columnspan=3, sticky=tk.W, pady=(10,0))
        
        ttk.Label(self.targets_container, text="Вкл.", font=("", 9, "bold")).grid(row=0, column=0, padx=2)
        ttk.Label(self.targets_container, text="Название / Описание", font=("", 9, "bold")).grid(row=0, column=1, padx=2)
        ttk.Label(self.targets_container, text="Chat ID", font=("", 9, "bold")).grid(row=0, column=2, padx=2)

        self.add_target_btn = ttk.Button(tg_frame, text="➕ Добавить получателя", command=self._add_target_row)
        self.add_target_btn.grid(row=3, column=0, pady=10, sticky=tk.W)

        # --- CRM ---
        crm_frame = ttk.LabelFrame(scrollable_frame, text="🖥️ Интеграция с RDP CRM", padding=(10, 10))
        crm_frame.pack(fill=tk.X, padx=10, pady=5, expand=True)
        
        self.crm_enabled_var = tk.BooleanVar(value=False)
        self.crm_enabled_chk = ttk.Checkbutton(crm_frame, text="Включить отправку событий в CRM", variable=self.crm_enabled_var, command=self._toggle_crm_fields)
        self.crm_enabled_chk.grid(row=0, column=0, columnspan=3, sticky=tk.W, pady=2)
        
        ttk.Label(crm_frame, text="URL CRM:").grid(row=1, column=0, sticky=tk.W, pady=2)
        self.crm_url_entry = ttk.Entry(crm_frame, width=50)
        self.crm_url_entry.grid(row=1, column=1, columnspan=2, sticky=tk.W, padx=5, pady=2)
        
        ttk.Label(crm_frame, text="API Токен:").grid(row=2, column=0, sticky=tk.W, pady=2)
        token_subframe = ttk.Frame(crm_frame)
        token_subframe.grid(row=2, column=1, columnspan=2, sticky=tk.W, padx=5, pady=2)
        
        self.crm_token_entry = ttk.Entry(token_subframe, width=42, show="*")
        self.crm_token_entry.pack(side=tk.LEFT)
        
        self.show_token_var = tk.BooleanVar(value=False)
        self.show_token_chk = ttk.Checkbutton(token_subframe, text="👁️", variable=self.show_token_var, command=self._toggle_token_visibility)
        self.show_token_chk.pack(side=tk.LEFT, padx=(5, 0))

        self.btn_test_crm = ttk.Button(crm_frame, text="Проверить соединение", command=self.test_crm_connection)
        self.btn_test_crm.grid(row=3, column=0, columnspan=3, pady=5)

        # --- СОХРАНЕНИЕ ---
        save_btn = ttk.Button(scrollable_frame, text="💾 Сохранить настройки", command=self.save_settings)
        save_btn.pack(pady=15)

    def _run_cmd(self, command):
        creation_flags = 0x08000000 
        result = subprocess.run(command, capture_output=True, text=True, shell=True, creationflags=creation_flags)
        out = (result.stdout or "").strip()
        err = (result.stderr or "").strip()
        return result.returncode, out, err

    def _get_execution_command(self):
        if getattr(sys, 'frozen', False):
            exe_path = sys.executable
            return f'"{exe_path}" --service'
        else:
            python_path = sys.executable
            script_path = os.path.abspath(sys.argv[0])
            return f'"{python_path}" "{script_path}" --service'

    def install_service(self):
        if getattr(sys, 'frozen', False):
            exe_path = sys.executable
            tr_value = f'\"{exe_path}\" --service'
        else:
            python_path = sys.executable
            script_path = os.path.abspath(sys.argv[0])
            tr_value = f'\"{python_path}\" \"{script_path}\" --service'
        
        creation_flags = 0x08000000
        args = [
            'schtasks', '/create',
            '/tn', TASK_NAME,
            '/tr', tr_value,
            '/sc', 'onstart',
            '/ru', 'SYSTEM',
            '/rl', 'HIGHEST',
            '/f'
        ]
        result = subprocess.run(args, capture_output=True, text=True, creationflags=creation_flags)
        code = result.returncode
        out = (result.stdout or '').strip()
        err = (result.stderr or '').strip()
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
        try:
            import win32com.client
            scheduler = win32com.client.Dispatch('Schedule.Service')
            scheduler.Connect()
            root_folder = scheduler.GetFolder('\\')
            try:
                task = root_folder.GetTask(TASK_NAME)
                state = task.State
                if state == 4:
                    self.svc_status_lbl.config(text="Статус службы: АКТИВНА (Работает в фоне)", foreground="green")
                elif state == 3:
                    self.svc_status_lbl.config(text="Статус службы: ОСТАНОВЛЕНА (Готова к запуску)", foreground="blue")
                elif state == 1:
                    self.svc_status_lbl.config(text="Статус службы: ОТКЛЮЧЕНА", foreground="gray")
                else:
                    self.svc_status_lbl.config(text=f"Статус службы: Установлена (код: {state})", foreground="black")
            except Exception:
                self.svc_status_lbl.config(text="Статус службы: Не установлена", foreground="red")
        except Exception:
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
        self.server_name_entry.delete(0, tk.END)
        self.server_name_entry.insert(0, self.config.get('server_name', ''))
        
        self.retention_entry.delete(0, tk.END)
        self.retention_entry.insert(0, str(self.config.get('retention_days', 7)))

        self.token_entry.delete(0, tk.END)
        self.token_entry.insert(0, self.config.get('bot_token', ''))

        self.crm_enabled_var.set(self.config.get('crm_enabled', False))
        self.crm_url_entry.delete(0, tk.END)
        self.crm_url_entry.insert(0, self.config.get('crm_url', ''))
        self.crm_token_entry.delete(0, tk.END)
        self.crm_token_entry.insert(0, self.config.get('crm_token', ''))
        self._toggle_crm_fields()

        for row in self.telegram_targets_vars[:]: 
            self._remove_target_row(row)
        for chat in self.config.get('telegram_chats', []): 
            self._add_target_row(chat.get('active', True), chat.get('name', ''), chat.get('id', ''))
        if not self.config.get('telegram_chats'): 
            self._add_target_row()

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

    def _toggle_crm_fields(self):
        state = 'normal' if self.crm_enabled_var.get() else 'disabled'
        self.crm_url_entry.config(state=state)
        self.crm_token_entry.config(state=state)
        self.show_token_chk.config(state=state)
        self.btn_test_crm.config(state=state)

    def _toggle_token_visibility(self):
        if self.show_token_var.get():
            self.crm_token_entry.config(show="")
        else:
            self.crm_token_entry.config(show="*")

    def test_crm_connection(self):
        url = self.crm_url_entry.get().strip()
        token = self.crm_token_entry.get().strip()
        if not url or not token:
            messagebox.showwarning("Предупреждение", "Заполните URL и Токен для проверки.")
            return
        
        test_url = f"{url.rstrip('/')}/api/auth.php"
        headers = {
            'Authorization': f'Bearer {token}',
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        }
        try:
            resp = requests.post(test_url, json={}, headers=headers, timeout=5)
            if resp.status_code == 200:
                data = resp.json()
                if data.get('success', False):
                    perms = ", ".join(data.get('permissions', []))
                    messagebox.showinfo("Успех", f"Успешное подключение!\nРазрешения токена: {perms}")
                else:
                    messagebox.showerror("Ошибка", f"Ошибка авторизации: {data.get('error', 'неизвестная ошибка')}")
            else:
                messagebox.showerror("Ошибка", f"Ошибка сервера: HTTP {resp.status_code}\n{resp.text}")
        except Exception as e:
            messagebox.showerror("Ошибка", f"Не удалось связаться с CRM:\n{e}")

    def send_crm_notifications(self, server_name, username, session_id, event_type, ip_address):
        if not self.config.get('crm_enabled', False):
            return
        crm_url = self.config.get('crm_url', '')
        crm_token = self.config.get('crm_token', '')
        if not crm_url or not crm_token:
            return
        
        url = f"{crm_url.rstrip('/')}/api/rdp_event.php"
        headers = {
            'Authorization': f'Bearer {crm_token}',
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        }
        payload = {
            'server_name': server_name,
            'username': username,
            'session_id': int(session_id),
            'event_type': event_type,
            'ip_address': ip_address
        }
        try:
            resp = requests.post(url, json=payload, headers=headers, timeout=5)
            if resp.status_code not in (200, 201):
                msg = f"CRM API error: HTTP {resp.status_code} - {resp.text}"
                logging.error(msg)
                log_error_to_file(msg)
        except Exception as e:
            msg = f"Ошибка отправки в CRM: {e}"
            logging.error(msg)
            log_error_to_file(msg)

    def save_settings(self):
        if not self.is_admin_user:
            messagebox.showerror("Ошибка доступа", "Настройки можно изменять только запустив программу от имени Администратора.")
            return

        self.config['server_name'] = self.server_name_entry.get().strip()
        self.config['bot_token'] = self.token_entry.get().strip()
        self.config['db_type'] = 'sqlite'
        try: self.config['retention_days'] = int(self.retention_entry.get() or 0)
        except ValueError: self.config['retention_days'] = 7
        
        self.config['telegram_chats'] = [{'active': r['active'].get(), 'name': r['name'].get().strip(), 'id': r['id'].get().strip()} for r in self.telegram_targets_vars if r['id'].get().strip()]
        self.config['crm_enabled'] = self.crm_enabled_var.get()
        self.config['crm_url'] = self.crm_url_entry.get().strip()
        self.config['crm_token'] = self.crm_token_entry.get().strip()

        try:
            self._save_config_data()
            self.db_manager = DatabaseManager(self.config)
            threading.Thread(target=self.db_manager.init_db, daemon=True).start()
            
            # Попытка автоматического перезапуска службы
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
        self.send_crm_notifications(server_name, user, sid, event_title, client_info)

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
                    if old_state == 4 and state == 0: self.process_event("Переподключение к RDP", user, sid, c_info, "🔄")
                    elif old_state == 0 and state == 4: self.process_event("Отключение от RDP (Свернуто)", user, sid, c_info, "⏸")
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

        # CRM Event submission
        if config.get('crm_enabled', False):
            crm_url = config.get('crm_url', '')
            crm_token = config.get('crm_token', '')
            if crm_url and crm_token:
                url = f"{crm_url.rstrip('/')}/api/rdp_event.php"
                headers = {
                    'Authorization': f'Bearer {crm_token}',
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }
                payload = {
                    'server_name': server_name,
                    'username': user,
                    'session_id': int(sid),
                    'event_type': event_title,
                    'ip_address': client_info
                }
                try:
                    resp = requests.post(url, json=payload, headers=headers, timeout=5)
                    if resp.status_code not in (200, 201):
                        msg = f"CRM API error: HTTP {resp.status_code} - {resp.text}"
                        logging.error(msg)
                        log_error_to_file(msg)
                except Exception as e:
                    msg = f"Ошибка отправки в CRM: {e}"
                    logging.error(msg)
                    log_error_to_file(msg)

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
                    if old_state == 4 and state == 0: process_headless_event("Переподключение к RDP", user, sid, c_info, "🔄")
                    elif old_state == 0 and state == 4: process_headless_event("Отключение от RDP (Свернуто)", user, sid, c_info, "⏸")
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