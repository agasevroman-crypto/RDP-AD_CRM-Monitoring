import tkinter as tk
from tkinter import ttk, scrolledtext, messagebox, filedialog
import threading
import time
import requests
import logging
import sqlite3
import json
import os
import sys
import socket
import subprocess
import ctypes
from datetime import datetime, timedelta
import xml.etree.ElementTree as ET
import win32evtlog

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
BASE_DIR = r"C:\AD_Monitor"

try:
    os.makedirs(BASE_DIR, exist_ok=True)
except PermissionError:
    print(f"КРИТИЧЕСКАЯ ОШИБКА: Нет прав для создания папки {BASE_DIR}. Запустите скрипт от имени Администратора.")
    sys.exit(1)

CONFIG_FILE = os.path.join(BASE_DIR, 'ad_monitor_config.json')
DB_FILE = os.path.join(BASE_DIR, 'ad_events_history.db')
TASK_NAME = "AD_Monitor_System_Service"

# Настройка логирования исключительно в оперативную память
logging.basicConfig(
    level=logging.INFO, 
    format='%(asctime)s - %(message)s',
    handlers=[logging.StreamHandler(sys.stdout)]
)

AD_EVENTS = {
    4720: ("Создание пользователя", "🆕"),
    4722: ("Включение учетной записи", "✅"),
    4723: ("Попытка смены пароля", "🔑"),
    4724: ("Сброс пароля админом", "⚠️"),
    4725: ("Блокировка учетной записи", "🚫"),
    4726: ("Удаление пользователя", "❌"),
    4728: ("Добавление в глоб. группу", "👥"),
    4729: ("Удаление из глоб. группы", "➖"),
    4732: ("Добавление в лок. группу", "🛡️"),
    4733: ("Удаление из лок. группы", "➖"),
    4756: ("Добавление в унив. группу", "🌐"),
    4757: ("Удаление из унив. группы", "➖"),
    4738: ("Изменение свойств", "📝"),
    4740: ("Авто-Блокировка", "🔒"),
    4767: ("Снятие авто-блокировки", "🔓"),
    4741: ("Создание ПК", "🖥️🆕"),
    4742: ("Изменение ПК", "🖥️📝"),
    4743: ("Удаление ПК", "🖥️❌"),
}

ALL_MONITORED_EVENTS = set(AD_EVENTS.keys())

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

def send_telegram_notifications(config, text):
    token = config.get('bot_token')
    if not token: return
    url = f"https://api.telegram.org/bot{token}/sendMessage"
    for chat in config.get('telegram_chats', []):
        if chat.get('active'):
            try: requests.post(url, json={"chat_id": chat.get('id'), "text": text, "parse_mode": "HTML"}, timeout=5)
            except Exception as e: logging.error(f"Ошибка Telegram ({chat.get('name')}): {e}")

def send_crm_notifications(config, dc_name, event_id, action_type, target_user, caller_user, details=""):
    if not config.get('crm_enabled', False):
        return
    crm_url = config.get('crm_url', '')
    crm_token = config.get('crm_token', '')
    if not crm_url or not crm_token:
        return
    
    url = f"{crm_url.rstrip('/')}/api/ad_event.php"
    headers = {
        'Authorization': f'Bearer {crm_token}',
        'Content-Type': 'application/json',
        'Accept': 'application/json'
    }
    payload = {
        'dc_name': dc_name,
        'event_id': int(event_id),
        'action_type': action_type,
        'target_user': target_user,
        'caller_user': caller_user,
        'details': details
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

# ==========================================
# ПАРСИНГ СОБЫТИЙ ACTIVE DIRECTORY
# ==========================================
def parse_event_xml(xml_str):
    try:
        root = ET.fromstring(xml_str)
        ns = {'ns': 'http://schemas.microsoft.com/win/2004/08/events/event'}
        event_id = int(root.find('.//ns:System/ns:EventID', ns).text)
        record_id = int(root.find('.//ns:System/ns:EventRecordID', ns).text)
        
        event_data = {data.get('Name'): (data.text or "") for data in root.findall('.//ns:EventData/ns:Data', ns)}


        # --- Обработка событий администрирования AD (4720-4767) ---
        target_user = event_data.get('TargetUserName', 'Unknown')
        caller_user = event_data.get('SubjectUserName', 'Unknown')

        if 'MemberName' in event_data:
            member = event_data['MemberName'].split(',')[0].replace('CN=', '')
            comp_tag = " (ПК)" if "$" in member else ""
            target_user = f"{member}{comp_tag} ➡️ в {target_user}"

        is_target_computer = target_user.endswith('$')
        is_caller_computer = caller_user.endswith('$')

        if event_id in [4742, 4738] and is_target_computer and is_caller_computer and target_user == caller_user:
            return None 

        if is_target_computer: target_user = f"🖥️ {target_user[:-1]} (ПК)"
        if is_caller_computer: caller_user = f"⚙️ {caller_user[:-1]} (Система)"

        return {'record_id': record_id, 'event_id': event_id, 'target_user': target_user, 'caller_user': caller_user, 'details': str(event_data)}
    except Exception:
        return None

def get_latest_record_id(query):
    try:
        h_query = win32evtlog.EvtQuery("Security", win32evtlog.EvtQueryChannelPath | win32evtlog.EvtQueryReverseDirection, query)
        events = win32evtlog.EvtNext(h_query, 1)
        if events:
            parsed = parse_event_xml(win32evtlog.EvtRender(events[0], win32evtlog.EvtRenderEventXml))
            if parsed: return parsed['record_id']
    except Exception: pass
    return 0

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
                cursor.execute('''CREATE TABLE IF NOT EXISTS ad_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, 
                    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP, 
                    dc_name TEXT, 
                    event_id INTEGER, 
                    action_type TEXT, 
                    target_user TEXT, 
                    caller_user TEXT, 
                    details TEXT
                )''')
                conn.commit()
        except Exception as e: logging.error(f"Ошибка инициализации БД: {e}")

    def log_event(self, dc_name, event_id, action_type, target_user, caller_user, details=""):
        query_sq = '''INSERT INTO ad_logs (dc_name, event_id, action_type, target_user, caller_user, details) VALUES (?, ?, ?, ?, ?, ?)'''
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                cursor.execute(query_sq, (dc_name, event_id, action_type, target_user, caller_user, details))
                conn.commit()
        except Exception as e: logging.error(f"Ошибка записи в БД: {e}")

    def cleanup_old_records(self, retention_days):
        if retention_days <= 0: return 0
        cutoff_str = (datetime.now() - timedelta(days=retention_days)).strftime('%Y-%m-%d %H:%M:%S')
        try:
            with self._get_connection() as conn:
                cursor = conn.cursor()
                cursor.execute('DELETE FROM ad_logs WHERE timestamp < ?', (cutoff_str,))
                deleted = cursor.rowcount
                conn.commit()
                return deleted
        except Exception: return 0

# ==========================================
# ГРАФИЧЕСКОЕ ПРИЛОЖЕНИЕ (GUI РЕЖИМ)
# ==========================================
class ADMonitorApp:
    def __init__(self, root):
        self.root = root
        self.root.title("AD Monitor Pro (Stealth Mode)")
        self.root.geometry("800x600")
        self.root.protocol('WM_DELETE_WINDOW', self.hide_window)

        self.icon_path = resource_path('icon.ico')
        if os.path.exists(self.icon_path):
            try: self.root.iconbitmap(self.icon_path)
            except Exception: pass

        self.monitor_thread = None
        self.stop_event = threading.Event()
        self.tray_icon = None
        self.last_record_id = 0
        
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
            'dc_name': socket.gethostname(),
            'bot_token': '', 
            'telegram_chats': [], 
            'db_type': 'sqlite',
            'retention_days': 0,
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
        except Exception as e: logging.error(f"Ошибка сохранения: {e}")

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
        info_frame = ttk.LabelFrame(self.tab_live, text="Информация", padding=5)
        info_frame.pack(fill=tk.X, padx=10, pady=5)
        ttk.Label(info_frame, text="ВНИМАНИЕ: Основной мониторинг AD должен выполняться в фоновом режиме.\nЗдесь вы можете запустить временный тест для проверки корректности настроек.", foreground="blue", justify=tk.LEFT).pack(anchor=tk.W)

        control_frame = ttk.LabelFrame(self.tab_live, text="Локальный тестовый мониторинг", padding=5)
        control_frame.pack(fill=tk.X, padx=10, pady=5)
        self.start_btn = ttk.Button(control_frame, text="▶ Запустить (Тест)", command=self.start_monitoring)
        self.start_btn.pack(side=tk.LEFT, padx=(0, 10))
        self.stop_btn = ttk.Button(control_frame, text="⏹ Остановить", command=self.stop_monitoring, state=tk.DISABLED)
        self.stop_btn.pack(side=tk.LEFT)
        self.status_label = ttk.Label(control_frame, text="Готов к тестированию", font=("Arial", 10, "bold"))
        self.status_label.pack(side=tk.RIGHT, padx=10)

        log_frame = ttk.LabelFrame(self.tab_live, text="Консоль событий (Local Live)", padding=5)
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
        svc_frame = ttk.LabelFrame(scrollable_frame, text="Управление системной службой (Windows Task Scheduler)", padding=5)
        svc_frame.grid(row=0, column=0, sticky="ew", padx=10, pady=2)
        svc_frame.columnconfigure(1, weight=1)
        
        if not self.is_admin_user:
            ttk.Label(svc_frame, text="⚠️ ВНИМАНИЕ: Для управления службой запустите программу от имени Администратора!", foreground="red", font=("", 9, "bold")).grid(row=0, column=0, columnspan=2, sticky=tk.W, pady=(0, 10))

        btn_container = ttk.Frame(svc_frame)
        btn_container.grid(row=1, column=0, columnspan=2, sticky="ew")
        
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
        self.svc_status_lbl.grid(row=2, column=0, columnspan=2, sticky=tk.W, pady=(10, 0))

        # --- НАСТРОЙКИ ДОМЕНА ---
        core_frame = ttk.LabelFrame(scrollable_frame, text="🌐 Настройки Домена и Очистки", padding=5)
        core_frame.grid(row=1, column=0, sticky="ew", padx=10, pady=2)
        core_frame.columnconfigure(1, weight=1) 

        ttk.Label(core_frame, text="Контроллер (DC):").grid(row=0, column=0, sticky=tk.W, pady=2)
        self.dc_name_entry = ttk.Entry(core_frame)
        self.dc_name_entry.grid(row=0, column=1, sticky="ew", padx=10, pady=2)
        
        ttk.Label(core_frame, text="Хранить логи (дней, 0 = бесконечно):").grid(row=1, column=0, sticky=tk.W, pady=2)
        self.retention_entry = ttk.Spinbox(core_frame, from_=0, to=365, width=10)
        self.retention_entry.grid(row=1, column=1, sticky="ew", padx=10, pady=2)

        # --- TELEGRAM ---
        tg_frame = ttk.LabelFrame(scrollable_frame, text="✈️ Уведомления Telegram", padding=5)
        tg_frame.grid(row=2, column=0, sticky="ew", padx=10, pady=2)
        tg_frame.columnconfigure(1, weight=1)

        ttk.Label(tg_frame, text="Bot Token:").grid(row=0, column=0, sticky=tk.W, pady=2)
        self.token_entry = ttk.Entry(tg_frame)
        self.token_entry.grid(row=0, column=1, sticky="ew", padx=10, pady=2)
        
        self.targets_container = ttk.Frame(tg_frame)
        self.targets_container.grid(row=1, column=0, columnspan=2, sticky="ew", pady=5)
        
        ttk.Label(self.targets_container, text="Вкл.", font=("", 9, "bold")).grid(row=0, column=0, padx=2)
        ttk.Label(self.targets_container, text="Название / Описание", font=("", 9, "bold")).grid(row=0, column=1, padx=5, sticky=tk.W)
        ttk.Label(self.targets_container, text="ID Чата / Группы", font=("", 9, "bold")).grid(row=0, column=2, padx=5, sticky=tk.W)

        ttk.Button(tg_frame, text="➕ Добавить чат", command=self._add_target_row).grid(row=2, column=0, pady=5, sticky=tk.W)

        # --- CRM ---
        crm_frame = ttk.LabelFrame(scrollable_frame, text="🖥️ Интеграция с RDP CRM", padding=5)
        crm_frame.grid(row=3, column=0, sticky="ew", padx=10, pady=2)
        crm_frame.columnconfigure(1, weight=1)
        
        self.crm_enabled_var = tk.BooleanVar(value=False)
        self.crm_enabled_chk = ttk.Checkbutton(crm_frame, text="Включить отправку событий в CRM", variable=self.crm_enabled_var, command=self._toggle_crm_fields)
        self.crm_enabled_chk.grid(row=0, column=0, columnspan=2, sticky=tk.W, pady=2)
        
        ttk.Label(crm_frame, text="URL CRM:").grid(row=1, column=0, sticky=tk.W, pady=2)
        self.crm_url_entry = ttk.Entry(crm_frame)
        self.crm_url_entry.grid(row=1, column=1, sticky="ew", padx=10, pady=2)
        
        ttk.Label(crm_frame, text="API Токен:").grid(row=2, column=0, sticky=tk.W, pady=2)
        token_subframe = ttk.Frame(crm_frame)
        token_subframe.grid(row=2, column=1, sticky="ew", padx=10, pady=2)
        token_subframe.columnconfigure(0, weight=1)
        
        self.crm_token_entry = ttk.Entry(token_subframe, show="*")
        self.crm_token_entry.grid(row=0, column=0, sticky="ew")
        
        self.show_token_var = tk.BooleanVar(value=False)
        self.show_token_chk = ttk.Checkbutton(token_subframe, text="👁️", variable=self.show_token_var, command=self._toggle_token_visibility)
        self.show_token_chk.grid(row=0, column=1, padx=(5, 0))
  
        self.btn_test_crm = ttk.Button(crm_frame, text="Проверить соединение", command=self.test_crm_connection)
        self.btn_test_crm.grid(row=3, column=0, columnspan=2, pady=5)
  
        # --- СОХРАНЕНИЕ ---
        save_btn = ttk.Button(scrollable_frame, text="💾 Сохранить настройки", command=self.save_settings)
        save_btn.grid(row=4, column=0, pady=15)

    def _run_cmd(self, command):
        creation_flags = 0x08000000 
        result = subprocess.run(command, capture_output=True, text=True, shell=True, creationflags=creation_flags)
        out = (result.stdout or "").strip()
        err = (result.stderr or "").strip()
        return result.returncode, out, err

    def _get_execution_command(self):
        if getattr(sys, 'frozen', False):
            return f'"{sys.executable}" --service'
        else:
            return f'"{sys.executable}" "{os.path.abspath(sys.argv[0])}" --service'

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
            messagebox.showinfo("Успех", "Служба AD Monitor успешно установлена.")
            self.check_service_status()
        else: messagebox.showerror("Ошибка", f"Не удалось создать службу:\n{err or out}")

    def remove_service(self):
        if messagebox.askyesno("Подтверждение", "Удалить системную службу?"):
            code, out, err = self._run_cmd(f'schtasks /delete /tn "{TASK_NAME}" /f')
            if code == 0:
                messagebox.showinfo("Успех", "Служба удалена.")
                self.check_service_status()
            else: messagebox.showerror("Ошибка", err or out)

    def start_sys_service(self):
        code, out, err = self._run_cmd(f'schtasks /run /tn "{TASK_NAME}"')
        if code == 0:
            self.svc_status_lbl.config(text="Статус: Запускается...", foreground="orange")
            self.root.after(2000, self.check_service_status)
        else: messagebox.showerror("Ошибка", err or out)

    def stop_sys_service(self):
        code, out, err = self._run_cmd(f'schtasks /end /tn "{TASK_NAME}"')
        if code == 0: self.check_service_status()
        else: messagebox.showerror("Ошибка", err or out)

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
                    self.svc_status_lbl.config(text="Статус: АКТИВНА (В фоне)", foreground="green")
                elif state == 3:
                    self.svc_status_lbl.config(text="Статус: ОСТАНОВЛЕНА", foreground="blue")
                elif state == 1:
                    self.svc_status_lbl.config(text="Статус: ОТКЛЮЧЕНА", foreground="gray")
                else:
                    self.svc_status_lbl.config(text=f"Статус: Установлена (код: {state})", foreground="black")
            except Exception:
                self.svc_status_lbl.config(text="Статус: Не установлена", foreground="red")
        except Exception:
            code, out, err = self._run_cmd(f'schtasks /query /tn "{TASK_NAME}" /fo LIST')
            if code != 0:
                self.svc_status_lbl.config(text="Статус: Не установлена", foreground="red")
                return
            if "Running" in out or "Работает" in out:
                self.svc_status_lbl.config(text="Статус: АКТИВНА (В фоне)", foreground="green")
            elif "Ready" in out or "Готов" in out:
                self.svc_status_lbl.config(text="Статус: ОСТАНОВЛЕНА", foreground="blue")
            else:
                self.svc_status_lbl.config(text="Статус: Установлена", foreground="black")

    def apply_config_to_gui(self):
        self.dc_name_entry.delete(0, tk.END)
        self.dc_name_entry.insert(0, self.config.get('dc_name', socket.gethostname()))
        
        self.retention_entry.delete(0, tk.END)
        self.retention_entry.insert(0, str(self.config.get('retention_days', 0)))
        
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

    def _toggle_crm_fields(self):
        state = tk.NORMAL if self.crm_enabled_var.get() else tk.DISABLED
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

    def _add_target_row(self, active=True, name="", chat_id=""):
        row = len(self.telegram_targets_vars) + 1
        var_active, var_name, var_id = tk.BooleanVar(value=active), tk.StringVar(value=name), tk.StringVar(value=chat_id)
        
        chk = ttk.Checkbutton(self.targets_container, variable=var_active)
        chk.grid(row=row, column=0, padx=5, pady=2)
        
        ent_name = ttk.Entry(self.targets_container, textvariable=var_name, width=25)
        ent_name.grid(row=row, column=1, padx=5, pady=2, sticky=tk.W)
        
        ent_id = ttk.Entry(self.targets_container, textvariable=var_id, width=25)
        ent_id.grid(row=row, column=2, padx=5, pady=2, sticky=tk.W)
        
        btn_del = ttk.Button(self.targets_container, text="❌", width=3, command=lambda: self._remove_target_row({'widgets': (chk, ent_name, ent_id, btn_del)}))
        btn_del.grid(row=row, column=3, padx=5, pady=2)
        
        self.telegram_targets_vars.append({'active': var_active, 'name': var_name, 'id': var_id, 'widgets': (chk, ent_name, ent_id, btn_del)})

    def _remove_target_row(self, row_data):
        for widget in row_data['widgets']: widget.destroy()
        if row_data in self.telegram_targets_vars: self.telegram_targets_vars.remove(row_data)

    def save_settings(self):
        if not self.is_admin_user: 
            return messagebox.showerror("Ошибка", "Запустите программу от имени Администратора для изменения настроек.")
        
        self.config['dc_name'] = self.dc_name_entry.get().strip()
        self.config['bot_token'] = self.token_entry.get().strip()
        self.config['db_type'] = 'sqlite'
        
        try: self.config['retention_days'] = int(self.retention_entry.get() or 0)
        except ValueError: self.config['retention_days'] = 0
            
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
        except Exception as e: messagebox.showerror("Ошибка", f"Сбой при сохранении: {str(e)}")

    def create_tray_icon_image(self):
        img = Image.new('RGB', (64, 64), (34, 139, 34))
        ImageDraw.Draw(img).rectangle((16, 16, 48, 48), outline=(255, 255, 255), width=4)
        return img

    def hide_window(self):
        self.root.withdraw()
        if GUI_LIBS_AVAILABLE:
            self.tray_icon = pystray.Icon("ADMonitor", self.create_tray_icon_image(), "AD Monitor", pystray.Menu(item('Развернуть', self.show_window, default=True), item('Выход', self.quit_application)))
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

    def monitor_logic(self):
        dc_name = self.config.get('dc_name', 'localhost')
        query = f"*[System[({ ' or '.join([f'EventID={eid}' for eid in ALL_MONITORED_EVENTS]) })]]"
        self.last_record_id = get_latest_record_id(query)
        self.log_message(f"✅ Локальный тест запущен. Маркер: {self.last_record_id}")

        while not self.stop_event.is_set():
            try:
                h_query = win32evtlog.EvtQuery("Security", win32evtlog.EvtQueryChannelPath | win32evtlog.EvtQueryReverseDirection, query)
                events = win32evtlog.EvtNext(h_query, 50)
                new_events = [parsed for event in events if (parsed := parse_event_xml(win32evtlog.EvtRender(event, win32evtlog.EvtRenderEventXml))) and parsed['record_id'] > self.last_record_id]
                
                for event_data in reversed(new_events):
                    eid = event_data['event_id']
                    if eid in AD_EVENTS:
                        # Событие администрирования AD — в CRM + Telegram
                        action, emoji = AD_EVENTS[eid]
                        self.db_manager.log_event(dc_name, eid, action, event_data['target_user'], event_data['caller_user'], event_data['details'])
                        self.log_message(f"{action} | Объект: {event_data['target_user']} | Admin: {event_data['caller_user']}")
                        send_telegram_notifications(self.config, f"{emoji} <b>Событие AD ({dc_name})</b>\n⚙️ <b>{action}</b>\n🎯 <code>{event_data['target_user']}</code>\n👮 <code>{event_data['caller_user']}</code>")
                        send_crm_notifications(self.config, dc_name, eid, action, event_data['target_user'], event_data['caller_user'], event_data['details'])
                    self.last_record_id = max(self.last_record_id, event_data['record_id'])
            except Exception: pass
            time.sleep(3)

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
    mutex = ctypes.windll.kernel32.CreateMutexW(None, False, "Global\\ADMonitorEnterpriseService")
    if ctypes.windll.kernel32.GetLastError() == 183:
        logging.error("КРИТИЧЕСКИЙ СБОЙ: Попытка запустить дубликат службы. Процесс прерван.")
        sys.exit(0)

    logging.info("=== Инициализация фоновой службы AD Monitor ===")
    config = {'dc_name': socket.gethostname(), 'db_type': 'sqlite'}
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE, 'r', encoding='utf-8') as f: config.update(json.load(f))
        except Exception as e: logging.error(f"Ошибка конфига: {e}")
            
    db_manager = DatabaseManager(config)
    db_manager.init_db()
    
    try:
        deleted = db_manager.cleanup_old_records(int(config.get('retention_days', 0)))
        if deleted > 0: logging.info(f"Очистка БД: удалено {deleted} записей.")
    except Exception as e: logging.error(f"Ошибка очистки БД: {e}")

    dc_name = config.get('dc_name', 'Unknown')
    query = f"*[System[({ ' or '.join([f'EventID={eid}' for eid in ALL_MONITORED_EVENTS]) })]]"
    last_record_id = get_latest_record_id(query)
    logging.info(f"Служба успешно стартовала на узле {dc_name}. Маркер: {last_record_id}")

    try:
        while True:
            try:
                h_query = win32evtlog.EvtQuery("Security", win32evtlog.EvtQueryChannelPath | win32evtlog.EvtQueryReverseDirection, query)
                events = win32evtlog.EvtNext(h_query, 50)
                new_events = [parsed for event in events if (parsed := parse_event_xml(win32evtlog.EvtRender(event, win32evtlog.EvtRenderEventXml))) and parsed['record_id'] > last_record_id]
                
                for event_data in reversed(new_events):
                    eid = event_data['event_id']
                    if eid in AD_EVENTS:
                        # Событие администрирования AD — в CRM + Telegram
                        action, emoji = AD_EVENTS[eid]
                        db_manager.log_event(dc_name, eid, action, event_data['target_user'], event_data['caller_user'], event_data['details'])
                        logging.info(f"[{action}] Цель: {event_data['target_user']}, Админ: {event_data['caller_user']}")
                        send_telegram_notifications(config, f"{emoji} <b>Событие AD ({dc_name})</b>\n⚙️ <b>{action}</b>\n🎯 <code>{event_data['target_user']}</code>\n👮 <code>{event_data['caller_user']}</code>")
                        send_crm_notifications(config, dc_name, eid, action, event_data['target_user'], event_data['caller_user'], event_data['details'])
                    last_record_id = max(last_record_id, event_data['record_id'])
            except Exception as e: logging.error(f"Сбой чтения журнала: {e}")
            time.sleep(3)
    except Exception as e: logging.critical(f"Критический сбой: {e}")

# ==========================================
# ТОЧКА ВХОДА
# ==========================================
if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == '--service':
        run_as_service()
    else:
        root = tk.Tk()
        app = ADMonitorApp(root)
        root.mainloop()