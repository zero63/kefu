<?php
/**
 * 文件变更监控 + 自动重载进程
 * 作者：kefu 开发团队
 * 创建时间：2026-07-31
 *
 * 说明：
 *   - 监听 app/、config/、.env 变更
 *   - 变更后通过 taskkill 重启子进程
 *   - webman 1.5 标准实现
 */
namespace app\process;

class Monitor
{
    /** 监控目录列表 */
    protected $monitorDir;
    /** 监控的文件扩展名 */
    protected $monitorExtensions;
    /** 文件上次 mtime 列表（path => mtime） */
    protected $lastMtime = [];
    /** 是否开启文件监控 */
    protected $enableFileMonitor = false;
    /** 是否开启内存监控 */
    protected $enableMemoryMonitor = false;

    public function __construct(
        $monitorDir = [],
        $monitorExtensions = ['php', 'html', 'htm', 'env'],
        $options = []
    ) {
        $this->monitorDir = $monitorDir;
        $this->monitorExtensions = $monitorExtensions;
        $this->enableFileMonitor = $options['enable_file_monitor'] ?? false;
        $this->enableMemoryMonitor = $options['enable_memory_monitor'] ?? false;
        // 首次扫描，记录 mtime
        if ($this->enableFileMonitor) {
            $this->scan(true);
        }
    }

    /**
     * 检查是否有文件变化
     */
    public function checkAllFilesChange()
    {
        if (!$this->enableFileMonitor) {
            return false;
        }
        return $this->scan(false);
    }

    /**
     * 扫描目录，对比 mtime
     * @param bool $init 是否初始化（只记录，不返回变化）
     * @return bool 是否有变化
     */
    protected function scan($init = false)
    {
        $changed = false;
        foreach ((array)$this->monitorDir as $dir) {
            if (!is_dir($dir)) continue;
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $file) {
                if ($file->isDir()) continue;
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, $this->monitorExtensions)) continue;
                $path = $file->getPathname();
                $mtime = $file->getMTime();
                if (!isset($this->lastMtime[$path])) {
                    $this->lastMtime[$path] = $mtime;
                    continue;
                }
                if ($this->lastMtime[$path] !== $mtime) {
                    $this->lastMtime[$path] = $mtime;
                    if (!$init) $changed = true;
                }
            }
        }
        return $changed;
    }
}