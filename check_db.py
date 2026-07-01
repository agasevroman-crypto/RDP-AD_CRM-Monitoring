import mysql.connector
import json

try:
    conn = mysql.connector.connect(
        host="localhost",
        database="apgk_rdp",
        user="apgk_rdp",
        password="orrz20054Q+"
    )
    cursor = conn.cursor()
    
    print("--- LAST 5 RDP EVENTS ---")
    cursor.execute("SELECT timestamp, server_name, username, event_type, ip_address FROM rdp_events ORDER BY id DESC LIMIT 5")
    for row in cursor.fetchall():
        print(f"Time: {row[0]}, Server: {row[1]}, User: {row[2]}, Event: {row[3]}, IP: {row[4]}")
        
    print("\n--- LAST 5 AD EVENTS ---")
    cursor.execute("SELECT timestamp, dc_name, event_id, action_type, target_user FROM ad_events ORDER BY id DESC LIMIT 5")
    for row in cursor.fetchall():
        print(f"Time: {row[0]}, DC: {row[1]}, ID: {row[2]}, Action: {row[3]}, Target: {row[4]}")
        
    conn.close()
except Exception as e:
    print(f"Error connecting to database: {e}")
