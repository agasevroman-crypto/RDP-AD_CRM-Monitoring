<?php
/**
 * helpers.php — Reusable PHP utility functions for the CRM
 */

/**
 * Build WHERE clause fragments for server-level access control.
 *
 * @param string $columnName  The SQL column name to filter (e.g. 'server_name' or 'dc_name')
 * @param array  $allowedServers  List of servers the user may access
 * @param string $selectedServer  Optional single-server filter from UI
 * @param bool   $isSuperAdmin    Whether the current user is a super admin
 * @return array ['sql' => string, 'params' => array]
 */
function build_server_filter(string $columnName, array $allowedServers, string $selectedServer, bool $isSuperAdmin): array {
    $sql    = '';
    $params = [];

    if (!$isSuperAdmin) {
        if (empty($allowedServers)) {
            $sql = ' AND 1=0 ';
        } elseif ($selectedServer !== '') {
            $sql      = " AND $columnName = ? ";
            $params[] = $selectedServer;
        } else {
            $ph  = implode(',', array_fill(0, count($allowedServers), '?'));
            $sql = " AND $columnName IN ($ph) ";
            $params = $allowedServers;
        }
    } else {
        if ($selectedServer !== '') {
            $sql      = " AND $columnName = ? ";
            $params[] = $selectedServer;
        }
    }

    return ['sql' => $sql, 'params' => $params];
}

/**
 * Format seconds into a human-readable Ukrainian string.
 */
function format_duration(?int $seconds): string {
    if ($seconds === null) return 'Активна';
    if ($seconds < 60)     return $seconds . ' сек';
    $mins = (int)floor($seconds / 60);
    if ($mins < 60)        return $mins . ' хв';
    $hours   = (int)floor($mins / 60);
    $remMins = $mins % 60;
    return $hours . ' год ' . $remMins . ' хв';
}

/**
 * Determine the CSS indicator class for an RDP event type.
 */
function rdp_event_indicator(string $eventType): string {
    $map = [
        // Ukrainian (translated by API)
        'Підключення до RDP'                => 'active',
        'Перепідключення до RDP'             => 'active',
        'Відключення від RDP (Згорнуто)'     => 'warning',
        'Завершення RDP-сеансу (Вихід)'      => 'danger',
        // Russian (legacy / raw from clients)
        'Подключение к RDP'                  => 'active',
        'Переподключение к RDP'              => 'active',
        'Отключение от RDP (Свернуто)'       => 'warning',
        'Завершение RDP-сеанса (Выход)'      => 'danger',
    ];
    return $map[$eventType] ?? 'inactive';
}

/**
 * Determine the CSS badge class for an AD action type.
 */
function ad_event_badge(string $actionType): string {
    if (str_contains($actionType, 'Создание') || str_contains($actionType, 'Включение')) return 'success';
    if (str_contains($actionType, 'Удаление') || str_contains($actionType, 'Блокировка')) return 'error';
    if (str_contains($actionType, 'парол')    || str_contains($actionType, 'Сброс'))      return 'warning';
    return 'info';
}

/**
 * Safe HTML escape shortcut. Supports nullable strings.
 */
function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Determine if a specific datetime is outside the given schedule.
 */
function is_outside_schedule(string $dateTimeStr, ?array $sched): bool {
    $timeVal = strtotime($dateTimeStr);
    $date = date('Y-m-d', $timeVal);
    $time = date('H:i:s', $timeVal);
    $dow = (int)date('N', $timeVal);
    
    if ($sched === null) {
        // Default schedule: Mon-Fri, 08:30:00 to 17:30:00
        $workDays = [1, 2, 3, 4, 5];
        if (!in_array($dow, $workDays)) {
            return true;
        }
        if ($time < '08:30:00' || $time > '17:30:00') {
            return true;
        }
        return false;
    }
    
    $type = $sched['schedule_type'] ?? 'mon_fri';
    if ($type === 'mon_fri') {
        $workDays = [1, 2, 3, 4, 5];
        if (!in_array($dow, $workDays)) {
            return true;
        }
        $wStart = $sched['work_start'] ?? '08:30:00';
        $wEnd   = $sched['work_end'] ?? '17:30:00';
        if ($time < $wStart || $time > $wEnd) {
            return true;
        }
        return false;
    } elseif ($type === 'custom') {
        $workDays = explode(',', $sched['work_days'] ?? '1,2,3,4,5');
        if (!in_array($dow, $workDays)) {
            return true;
        }
        $wStart = $sched['work_start'] ?? '08:30:00';
        $wEnd   = $sched['work_end'] ?? '17:30:00';
        if ($time < $wStart || $time > $wEnd) {
            return true;
        }
        return false;
    } elseif ($type === 'shift_24') {
        $ref = ($sched['ref_date'] ?? '') ?: '2026-06-01';
        $diff = (int)floor((strtotime($date) - strtotime($ref)) / 86400);
        $cycleDay = ($diff % 4 + 4) % 4;
        if ($cycleDay === 0) {
            return false;
        }
        return true;
    } elseif ($type === 'shift_12') {
        $ref = ($sched['ref_date'] ?? '') ?: '2026-06-01';
        $diff = (int)floor((strtotime($date) - strtotime($ref)) / 86400);
        $cycleDay = ($diff % 4 + 4) % 4;
        if ($cycleDay === 0) {
            if ($time >= '08:00:00' && $time <= '20:00:00') {
                return false;
            }
            return true;
        } elseif ($cycleDay === 1) {
            if ($time >= '20:00:00') {
                return false;
            }
            return true;
        } elseif ($cycleDay === 2) {
            if ($time <= '08:00:00') {
                return false;
            }
            return true;
        } else {
            return true;
        }
    } elseif ($type === 'always_off') {
        return true;
    }
    
    return false;
}

/**
 * Calculate seconds spent outside work hours for a session.
 */
function get_session_outside_duration(string $startStr, ?string $endStr, ?array $sched): int {
    $s = strtotime($startStr);
    $e = $endStr ? strtotime($endStr) : time();
    if ($s >= $e) return 0;
    
    if ($sched === null) {
        $sched = [
            'schedule_type' => 'mon_fri',
            'work_start' => '08:30:00',
            'work_end' => '17:30:00',
            'work_days' => '1,2,3,4,5',
            'ref_date' => null
        ];
    }
    
    $type = $sched['schedule_type'] ?? 'mon_fri';
    if ($type === 'always_off') {
        return $e - $s;
    }
    
    $totalOutside = 0;
    $currentDate = date('Y-m-d', $s);
    $endDate = date('Y-m-d', $e);
    
    $datePtr = $currentDate;
    while (true) {
        $dayStart = strtotime($datePtr . ' 00:00:00');
        $dayEnd   = strtotime($datePtr . ' 23:59:59');
        
        $subStart = max($s, $dayStart);
        $subEnd   = min($e, $dayEnd);
        
        if ($subStart < $subEnd) {
            $dayDuration = $subEnd - $subStart;
            $overlap = 0;
            
            if ($type === 'mon_fri' || $type === 'custom') {
                $dow = (int)date('N', $dayStart);
                $workDays = ($type === 'mon_fri') ? [1,2,3,4,5] : explode(',', $sched['work_days'] ?? '1,2,3,4,5');
                if (in_array($dow, $workDays)) {
                    $wStart = strtotime($datePtr . ' ' . ($sched['work_start'] ?? '08:30:00'));
                    $wEnd   = strtotime($datePtr . ' ' . ($sched['work_end'] ?? '17:30:00'));
                    $overlap = max(0, min($subEnd, $wEnd) - max($subStart, $wStart));
                }
            } elseif ($type === 'shift_24') {
                $ref = $sched['ref_date'] ?: '2026-06-01';
                $diff = (int)floor((strtotime($datePtr) - strtotime($ref)) / 86400);
                $cycleDay = ($diff % 4 + 4) % 4;
                if ($cycleDay === 0) {
                    $overlap = $dayDuration;
                }
            } elseif ($type === 'shift_12') {
                $ref = $sched['ref_date'] ?: '2026-06-01';
                $diff = (int)floor((strtotime($datePtr) - strtotime($ref)) / 86400);
                $cycleDay = ($diff % 4 + 4) % 4;
                
                $wStart = null;
                $wEnd = null;
                if ($cycleDay === 0) {
                    $wStart = strtotime($datePtr . ' 08:00:00');
                    $wEnd   = strtotime($datePtr . ' 20:00:00');
                } elseif ($cycleDay === 1) {
                    $wStart = strtotime($datePtr . ' 20:00:00');
                    $wEnd   = strtotime($datePtr . ' 23:59:59');
                } elseif ($cycleDay === 2) {
                    $wStart = strtotime($datePtr . ' 00:00:00');
                    $wEnd   = strtotime($datePtr . ' 08:00:00');
                }
                
                if ($wStart !== null && $wEnd !== null) {
                    $overlap = max(0, min($subEnd, $wEnd) - max($subStart, $wStart));
                }
            }
            
            $totalOutside += ($dayDuration - $overlap);
        }
        
        if ($datePtr === $endDate) {
            break;
        }
        $datePtr = date('Y-m-d', strtotime($datePtr . ' +1 day'));
    }
    
    return $totalOutside;
}
