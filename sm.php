<?php
error_reporting(E_ALL);
ini_set('display_errors', 1); // 调试时显示错误
header('Content-Type: text/plain; charset=utf-8');
date_default_timezone_set("Asia/Shanghai");

// 核心配置
const CONFIG = [
    'upstream'   => [
       'http://66.90.99.154:8278/'
       // 'http://198.16.100.186:8278/',
       // 'http://50.7.92.106:8278/',  // 确保URL格式完整
       // 'http://50.7.234.10:8278/',
       //  'http://50.7.220.170:8278/',
    ],
    'list_url'   => 'https://hellotv.dpdns.org/data/smartnew.txt',
    'token_ttl'  => 2400,  // 40分钟有效期
    'cache_ttl'  => 3600,  // 频道列表缓存1小时
    'code_ttl'   => 3600,  // 短码有效期（秒），过期后自动轮换
    'code_grace' => 600,   // 短码过期宽限（秒），用于不中断正在播放的 TS
    'map_file'   => '',    // 短码映射文件（为空则使用系统临时目录）
    'fallback'   => 'https://sf1-cdn-tos.huoshanstatic.com/obj/media-fe/xgplayer_doc_video/mp4/xgplayer-demo-360p.mp4', 
    'clear_key'  => 'hellotv',
    'backup_url' => 'https://hellotv.dpdns.org/smartold.txt'
];

//   获取上游服务器：
// - 提供 stickyKey 时进行粘性选择（按键哈希），保证同一频道命中同一上游
// - 无 stickyKey 时按轮询选择
function getUpstream($stickyKey = null) {
    $upstreams = CONFIG['upstream'];
    if (empty($upstreams)) {
        throw new Exception('No upstream configured');
    }
    if ($stickyKey !== null) {
        $idx = abs(crc32((string)$stickyKey)) % count($upstreams);
        return $upstreams[$idx];
    }
    static $index = 0;
    $current = $upstreams[$index % count($upstreams)];
    $index++;
    return $current;
}

// 主路由控制
try {
    // 定期清理过期缓存（每10%的请求概率执行）
    if (extension_loaded('apcu') && (mt_rand(1, 10) === 1)) {
        cleanupExpiredCache();
    }
    
    // 漂亮路径适配：/sm/{id}/index.m3u8 与 /sm/{id}/{ts}
    $pretty = detectPrettyRoute();
    if ($pretty) {
        if (!empty($pretty['id'])) {
            $_GET['id'] = $pretty['id'];
        }
        if (!empty($pretty['ts'])) {
            $_GET['ts'] = $pretty['ts'];
        }
    }
    if (isset($_GET['action']) && $_GET['action'] === 'clear_cache') {
        clearCache();
    } elseif (isset($_GET['action']) && $_GET['action'] === 'cache_status') {
        showCacheStatus();
    } elseif (!isset($_GET['id'])) {
        sendTXTList();
    } else {
        handleChannelRequest();
    }
} catch (Exception $e) {
    header('HTTP/1.1 503 Service Unavailable');
    exit("系统维护中，请稍后重试\n错误详情：" . $e->getMessage());
}

// 清理过期缓存
function cleanupExpiredCache() {
    if (!extension_loaded('apcu')) {
        return;
    }
    
    try {
        $cacheInfo = apcu_cache_info('user');
        if (!isset($cacheInfo['cache_list']) || !is_array($cacheInfo['cache_list'])) {
            return;
        }
        
        $now = time();
        $cleaned = 0;
        
        foreach ($cacheInfo['cache_list'] as $item) {
            if (isset($item['ttl']) && $item['ttl'] > 0) {
                $timeToExpire = $item['ttl'] - ($now - $item['mtime']);
                if ($timeToExpire <= 0) {
                    apcu_delete($item['key']);
                    $cleaned++;
                }
            }
        }
        
        if ($cleaned > 0) {
            error_log("[Cleanup] 清理了 $cleaned 个过期缓存项");
        }
    } catch (Exception $e) {
        error_log("[Cleanup] 清理缓存时出错: " . $e->getMessage());
    }
}

// 缓存清除   远程: https://你的域名/sm.php?action=clear_cache&key=hellotv
// 缓存清除   本机: http://127.0.0.1/sm.php?action=clear_cache
// 查看缓存装态                     /sm.php?action=cache_status
function clearCache() {
    error_log("[ClearCache] ClientIP:{$_SERVER['REMOTE_ADDR']}, Key:".($_GET['key']??'null'));

    $validKey = $_GET['key'] ?? '';
    $isLocal = in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']);
    if (!$isLocal && !hash_equals(CONFIG['clear_key'], $validKey)) {
        header('HTTP/1.1 403 Forbidden');
        exit("权限验证失败\nIP: {$_SERVER['REMOTE_ADDR']}\n密钥状态: ".(empty($validKey)?'未提供':'无效'));
    }

    $results = [];
    $cacheType = '';

    if (extension_loaded('apcu')) {
        $cacheType = 'APCu';
        $results[] = apcu_clear_cache() ? '✅ APCu缓存已清除' : '❌ APCu清除失败';
    } else {
        $results[] = '⚠️ APCu扩展未安装';
    }

    try {
        $list = getChannelList(true);
        if (empty($list)) throw new Exception("频道列表为空");
        $results[] = '📡 频道列表已重建 数量:' . count($list);
        $cacheType = $cacheType ?: '无缓存扩展';
        $results[] = "🔧 使用缓存类型: $cacheType";
    } catch (Exception $e) {
        $results[] = '⚠️ 列表重建失败: ' . $e->getMessage();
    }

    // 安全销毁会话（仅当会话已启动）
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        if (session_destroy()) {
            $results[] = '✅ Session已销毁';
        }
    } else {
        $results[] = 'ℹ️ Session未开启，跳过销毁';
    }

    // 清理短码映射文件（如果存在）
    $mapFile = getMapFilePath();
    if (is_file($mapFile)) {
        @unlink($mapFile);
        $results[] = '🗑️ 短码映射文件已删除';
    }

    header('Cache-Control: no-store');
    exit(implode("\n", $results));
}

// 显示缓存状态
function showCacheStatus() {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    
    $output = [];
    $output[] = "=== 缓存状态报告 ===";
    $output[] = "时间: " . date('Y-m-d H:i:s');
    $output[] = "";
    
    if (extension_loaded('apcu')) {
        $output[] = "✅ APCu 扩展已安装";
        
        try {
            $cacheInfo = apcu_cache_info('user');
            if (isset($cacheInfo['num_hits'], $cacheInfo['num_misses'])) {
                $output[] = "缓存命中率: " . round(($cacheInfo['num_hits'] / max(1, $cacheInfo['num_hits'] + $cacheInfo['num_misses'])) * 100, 2) . "%";
            }
            if (isset($cacheInfo['mem_size'])) {
                $output[] = "内存使用: " . formatBytes($cacheInfo['mem_size']);
            }
            $output[] = "";
            
            // 检查频道列表缓存
            $channelsData = apcu_fetch('smart_channels_data');
            if ($channelsData !== false && isset($channelsData['channels'], $channelsData['cache_start_time'], $channelsData['cache_expire_time'])) {
                $output[] = "📡 频道列表缓存: 正常";
                $output[] = "频道数量: " . count($channelsData['channels']);
                $output[] = "缓存开始时间: " . date('Y-m-d H:i:s', $channelsData['cache_start_time']);
                $output[] = "缓存失效时间: " . date('Y-m-d H:i:s', $channelsData['cache_expire_time']);
                
                $now = time();
                $timeToExpire = $channelsData['cache_expire_time'] - $now;
                $output[] = "剩余有效期: " . formatTime($timeToExpire);
            } else {
                $output[] = "❌ 频道列表缓存: 已过期或不存在";
            }
            
            // 检查短码映射缓存
            $codeMap = apcu_fetch('smart_code_map');
            if ($codeMap !== false) {
                $output[] = "🔗 短码映射缓存: 正常";
                $output[] = "映射数量: " . count($codeMap['id_to_code'] ?? []);
            } else {
                $output[] = "❌ 短码映射缓存: 已过期或不存在";
            }
            
        } catch (Exception $e) {
            $output[] = "❌ 获取缓存信息失败: " . $e->getMessage();
        }
    } else {
        $output[] = "❌ APCu 扩展未安装";
    }
    
    $output[] = "";
    $output[] = "=== 配置信息 ===";
    $output[] = "频道列表缓存时间: " . CONFIG['cache_ttl'] . " 秒 (" . formatTime(CONFIG['cache_ttl']) . ")";
    $output[] = "短码有效期: " . CONFIG['code_ttl'] . " 秒 (" . formatTime(CONFIG['code_ttl']) . ")";
    $output[] = "短码宽限期: " . CONFIG['code_grace'] . " 秒 (" . formatTime(CONFIG['code_grace']) . ")";
    
    exit(implode("\n", $output));
}

// 格式化字节数
function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

// 格式化时间
function formatTime($seconds) {
    if ($seconds <= 0) return "已过期";
    if ($seconds < 60) return $seconds . "秒";
    if ($seconds < 3600) return floor($seconds / 60) . "分钟";
    if ($seconds < 86400) return floor($seconds / 3600) . "小时";
    return floor($seconds / 86400) . "天";
}

// 生成TXT主列表
function sendTXTList() {
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");

    try {
        $channels = getChannelList();
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        exit("无法获取频道列表: " . $e->getMessage());
    }

    $baseUrl  = getBaseUrl();
    $prettyBase = $baseUrl . '/sm';
    
    $grouped = [];
    foreach ($channels as $chan) {
        $grouped[$chan['group']][] = $chan;
    }

    $output = '';
    foreach ($grouped as $group => $items) {
        $output .= htmlspecialchars($group) . ",#genre#\n";
        foreach ($items as $chan) {
            // 对于订阅信息频道，直接使用其URL
            if (isset($chan['url']) && !empty($chan['url'])) {
                $output .= sprintf("%s,%s\n",
                    htmlspecialchars($chan['name']),
                    $chan['url']
                );
            } else {
                // 其他频道输出漂亮路径：/sm/{code}/index.m3u8
                $code = idToCode($chan['id']);
                $output .= sprintf("%s,%s/%s/index.m3u8\n",
                    htmlspecialchars($chan['name']),
                    $prettyBase,
                    rawurlencode($code)
                );
            }
        }
        $output .= "\n";
    }

    header('Content-Disposition: inline; filename="channels_'.time().'.txt"');
    echo trim($output);
}

// 获取频道列表（仅内存缓存）
function getChannelList($forceRefresh = false) {
    if (!$forceRefresh && extension_loaded('apcu')) {
        $channelsData = apcu_fetch('smart_channels_data');
        if ($channelsData !== false && isset($channelsData['channels'], $channelsData['cache_start_time'], $channelsData['cache_expire_time'])) {
            // 检查缓存是否已过期
            $now = time();
            if ($now < $channelsData['cache_expire_time']) {
                // 如果缓存将在5分钟内过期，提前刷新
                if (($channelsData['cache_expire_time'] - $now) <= 300) {
                    $forceRefresh = true;
                } else {
                    // 更新订阅信息频道的剩余时间显示
                    $channels = $channelsData['channels'];
                    $timeRemaining = $channelsData['cache_expire_time'] - $now;
                    $remainingText = formatTime($timeRemaining);
                    
                    // 更新第一个订阅频道的名称
                    foreach ($channels as &$chan) {
                        if ($chan['id'] === 'cache_status_valid') {
                            $chan['name'] = "剩余有效期:{$remainingText}";
                            break;
                        }
                    }
                    unset($chan); // 清除引用
                    
                    return $channels;
                }
            } else {
                $forceRefresh = true;
            }
        } else {
            $forceRefresh = true;
        }
    }

    $raw = fetchWithRetry(CONFIG['list_url'], 3);
    if ($raw === false) {
        $raw = fetchWithRetry(CONFIG['backup_url'], 2);
        if ($raw === false) {
            throw new Exception("所有数据源均不可用");
        }
    }

    $list = [];
    $currentGroup = '默认分组';
    foreach (explode("\n", trim($raw)) as $line) {
        $line = trim($line);
        if (!$line) continue;

        if (strpos($line, '#genre#') !== false) {
            $currentGroup = trim(str_replace(',#genre#', '', $line));
            continue;
        }

        $id = null;
        if (preg_match('/\/\/:id=(\w+)/', $line, $m)) {
            $id = $m[1];
            $name = trim(explode(',', $line)[0]); // 修复括号
        } elseif (preg_match('/[?&]id=([^&]+)/', $line, $m)) {
            $id = $m[1];
            $name = trim(explode(',', $line)[0]); // 修复括号
        }

        if ($id) {
            $list[] = [
                'id'    => $id,
                'name'  => $name,
                'group' => $currentGroup,
                'logo'  => ''
            ];
        }
    }

    if (empty($list)) {
        throw new Exception("频道列表解析失败");
    }

    // 在列表最前面添加"我的订阅"分组
    $now = time();
    $expireTime = $now + CONFIG['cache_ttl'];
    $timeRemaining = $expireTime - $now;
    
    // 格式化剩余时间
    $remainingText = formatTime($timeRemaining);
    
    // 创建订阅信息频道
    $subscriptionChannels = [
        [
            'id' => 'cache_status_valid',
            'name' => "剩余有效期:{$remainingText}",
            'group' => '我的订阅',
            'logo' => '',
            'url' => 'https://hellotv.dpdns.org/stream/dy.mp4'
        ],
        [
            'id' => 'cache_status_refresh',
            'name' => '不在有效期请刷新订阅',
            'group' => '我的订阅',
            'logo' => '',
            'url' => 'https://hellotv.dpdns.org/stream/dy.mp4'
        ]
    ];
    
    // 将订阅频道添加到列表最前面
    $list = array_merge($subscriptionChannels, $list);

    if (extension_loaded('apcu')) {
        $channelsData = [
            'channels' => $list,
            'cache_start_time' => $now,
            'cache_expire_time' => $expireTime
        ];
        
        apcu_store('smart_channels_data', $channelsData, CONFIG['cache_ttl']);
        error_log("[ChannelList] 频道列表已更新，共 " . count($list) . " 个频道（含订阅信息），缓存开始时间: " . date('Y-m-d H:i:s', $now) . "，失效时间: " . date('Y-m-d H:i:s', $expireTime));
    }

    return $list;
}

// 带重试机制的获取函数
function fetchWithRetry($url, $maxRetries = 3) {
    $retryDelay = 500; // 毫秒
    $lastError = '';
    
    for ($i = 0; $i < $maxRetries; $i++) {
        try {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'header' => "User-Agent: Mozilla/5.0\r\n"
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw !== false) {
                return $raw;
            }
            $lastError = error_get_last()['message'] ?? '未知错误';
            
        } catch (Exception $e) {
            $lastError = $e->getMessage();
        }
        
        if ($i < $maxRetries - 1) {
            usleep($retryDelay * 1000);
            $retryDelay *= 2; // 指数退避
        }
    }
    
    error_log("[Fetch] 获取失败: $url, 错误: $lastError");
    return false;
}

// 处理频道请求
function handleChannelRequest() {
    $rawIdentifier = $_GET['id'];
    $channelId = resolveChannelId($rawIdentifier);
    $tsFile    = $_GET['ts'] ?? '';

    if ($tsFile) {
        // 避免并发 TS 请求被 Session 锁阻塞
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        proxyTS($channelId, $tsFile);
    } else {
        // 若路径携带的是有效短码，则优先使用该短码生成 m3u8 内 TS 链接，避免切码带来的不连续
        $preferredCode = codeToId($rawIdentifier, true) ? $rawIdentifier : null;
        $token = manageToken();
        // 生成完 token 后尽快释放 Session 锁
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        generateM3U8($channelId, $token, $preferredCode);
    }
}

// Token管理
function manageToken() {
    // 延迟开启 session，仅在需要生成或校验 token 时开启
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $token = $_GET['token'] ?? '';
    
    if (empty($_SESSION['token']) || 
        !hash_equals($_SESSION['token'], $token) || 
        (time() - $_SESSION['token_time']) > CONFIG['token_ttl']) {
        
        $token = bin2hex(random_bytes(16));
        $_SESSION = [
            'token'      => $token,
            'token_time' => time()
        ];
        
        if (isset($_GET['ts'])) {
            // 重定向为漂亮路径：/sm/{code}/{ts}?token=...
            $realId = resolveChannelId($_GET['id']);
            $code = idToCode($realId);
            $tsFile = $_GET['ts'];
            $url = getBaseUrl() . '/sm/' . rawurlencode($code) . '/' . $tsFile . '?' . http_build_query([
                'token' => $token
            ]);
            header("Location: $url");
            exit();
        }
    }
    
    return $token;
}

// 生成M3U8播放列表
function generateM3U8($channelId, $token, $preferredCode = null) {
    $upstream = getUpstream($channelId);
    $authUrl = $upstream . "$channelId/playlist.m3u8?" . http_build_query([
        'tid'  => 'mc42afe745533',
        'ct'   => intval(time() / 150),
        'tsum' => md5("tvata nginx auth module/$channelId/playlist.m3u8mc42afe745533" . intval(time() / 150))
    ]);
    
    $content = fetchUrl($authUrl);
    if (strpos($content, '404 Not Found') !== false) {
        header("Location: " . CONFIG['fallback']);
        exit();
    }
    
    // 输出漂亮路径的 TS 链接：/sm/{code}/{ts}?token=...
    $code = $preferredCode ?: idToCode($channelId);
    $routeBase = getBaseUrl() . '/sm/' . rawurlencode($code);
    $content = preg_replace_callback('/(\S+\.ts)/', function($m) use ($routeBase, $token) {
        return $routeBase . '/' . $m[1] . '?token=' . urlencode($token);
    }, $content);
    
    header('Content-Type: application/vnd.apple.mpegurl');
    echo $content;
}

// 代理TS流
function proxyTS($channelId, $tsFile) {
    $upstream = getUpstream($channelId);
    $url = $upstream . "$channelId/$tsFile";
    $data = fetchUrl($url);
    
    if ($data === null) {
        header('HTTP/1.1 404 Not Found');
        exit();
    }
    
    header('Content-Type: video/MP2T');
    header('Content-Length: ' . strlen($data));
    echo $data;
}

// 通用URL获取
function fetchUrl($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["CLIENT-IP: 127.0.0.1", "X-FORWARDED-FOR: 127.0.0.1"],
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $code == 200 ? $data : null;
}

// ========== 短码与频道ID映射 ==========
function getCodeMap($forceRefresh = false, $readonly = false) {
    static $inMemoryMap = null;

    if (!$forceRefresh) {
        if (extension_loaded('apcu')) {
            $cached = apcu_fetch('smart_code_map');
            if ($cached !== false && is_array($cached)) return $cached;
        } elseif ($inMemoryMap !== null) {
            return $inMemoryMap;
        }

        // 文件缓存回退
        $file = getMapFilePath();
        if (is_readable($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    if (extension_loaded('apcu')) apcu_store('smart_code_map', $decoded, CONFIG['cache_ttl']);
                    else $inMemoryMap = $decoded;
                    return $decoded;
                }
            }
        }
    }

    // 只读模式避免在 TS 请求中触发远端列表拉取
    $channels = $readonly ? (extension_loaded('apcu') ? (apcu_fetch('smart_channels_data')['channels'] ?? []) : []) : getChannelList();
    $idToCode = [];
    $codeToId = [];
    $codeExpire = [];

    // 尝试沿用旧映射，避免频繁轮换
    $old = extension_loaded('apcu') ? apcu_fetch('smart_code_map') : $inMemoryMap;
    if (is_array($old) && isset($old['id_to_code'], $old['code_to_id'])) {
        $idToCode = $old['id_to_code'];
        $codeToId = $old['code_to_id'];
        $codeExpire = $old['code_expire'] ?? [];
    }

    foreach ($channels as $chan) {
        $id = $chan['id'];
        $now = time();
        $needNewCode = true;
        if (isset($idToCode[$id])) {
            $code = $idToCode[$id];
            $exp = $codeExpire[$code] ?? 0;
            if ($exp > $now) {
                $needNewCode = false; // 仍在有效期
            } else {
                // 已过期：保留旧 code->id 映射用于宽限期，生成新码
            }
        }

        if ($needNewCode) {
            // 生成唯一8位数字码
            do {
                $code = str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            } while (isset($codeToId[$code]));
            $idToCode[$id] = $code;
            $codeToId[$code] = $id;
            $codeExpire[$code] = $now + (CONFIG['code_ttl'] ?? 3600);
        }
    }

    $map = [
        'id_to_code' => $idToCode,
        'code_to_id' => $codeToId,
        'code_expire'=> $codeExpire,
    ];

    if (extension_loaded('apcu')) {
        apcu_store('smart_code_map', $map, CONFIG['cache_ttl']);
    } else {
        $inMemoryMap = $map; // 进程内回退
    }

    // 文件缓存写入
    $file = getMapFilePath();
    @file_put_contents($file, json_encode($map, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

    return $map;
}

function idToCode($channelId) {
    $map = getCodeMap();
    if (isset($map['id_to_code'][$channelId])) return $map['id_to_code'][$channelId];
    // 无法找到时使用可逆回退：哈希截取，避免中断（尽量不发生）
    $hash = substr(str_pad((string)abs(crc32('sm_salt_' . $channelId)), 10, '0', STR_PAD_LEFT), 0, 8);
    return $hash;
}

function codeToId($code, $allowGrace = true) {
    $map = getCodeMap(false, true);
    $id = $map['code_to_id'][$code] ?? null;
    if ($id === null) return null;
    // 校验有效期（含宽限期）
    $now = time();
    $exp = $map['code_expire'][$code] ?? 0;
    if ($exp <= $now) {
        if ($allowGrace && ($now - $exp) <= (CONFIG['code_grace'] ?? 0)) {
            return $id; // 宽限期内允许继续使用，避免播放中断
        }
        return null;
    }
    return $id;
}

function resolveChannelId($identifier) {
    // 如果能通过短码匹配，则返回真实ID；否则认为本身就是ID
    $real = codeToId($identifier, true);
    return $real ?: $identifier;
}

function getMapFilePath() {
    $custom = CONFIG['map_file'] ?? '';
    if (!empty($custom)) return $custom;
    $tmp = sys_get_temp_dir();
    return rtrim($tmp, '/\\') . DIRECTORY_SEPARATOR . 'sm_code_map.json';
}
// 解析漂亮路径
function detectPrettyRoute() {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (!$path) return null;

    // /sm/{id}/index.m3u8 → 生成 m3u8
    if (preg_match('#^/sm/([^/]+)/index\\.m3u8$#', $path, $m)) {
        return [
            'id' => urldecode($m[1]),
            'ts' => ''
        ];
    }

    // /sm/{id}/{ts} → 代理 ts
    if (preg_match('#^/sm/([^/]+)/(.+\\.ts)$#', $path, $m)) {
        return [
            'id' => urldecode($m[1]),
            'ts' => $m[2]
        ];
    }

    return null;
}

// 获取基础URL
function getBaseUrl() {
    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
           . "://$_SERVER[HTTP_HOST]";
}