<?php
/**
 * 文件上传接口
 * 作者：kefu 开发团队
 * 创建时间：2026-07-29
 * 说明：
 *   - 接收 $_FILES['file']，保存到 public/uploads/ 目录
 *   - 限制 5MB，允许 jpg/png/gif/pdf/doc/xls/zip
 *   - 文件名格式：Ymd_uniqid.ext
 */
namespace app\controller\common;

use support\Request;
use Webman\Http\UploadFile;

class UploadController
{
    // 允许的文件扩展名（涵盖图片/文档/音频/视频）
    private static $allowedExts = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg',  // 图片
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'rar', '7z',  // 文档
        'mp3', 'wav', 'm4a', 'aac', 'ogg', 'amr',           // 音频
        'mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v',          // 视频
    ];

    // 扩展名 → 业务类型映射（用于返回 type 字段，便于前端识别）
    private static $typeMap = [
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image',
        'webp' => 'image', 'bmp' => 'image', 'svg' => 'image',
        'mp3' => 'audio', 'wav' => 'audio', 'm4a' => 'audio', 'aac' => 'audio',
        'ogg' => 'audio', 'amr' => 'audio',
        'mp4' => 'video', 'webm' => 'video', 'mov' => 'video', 'avi' => 'video',
        'mkv' => 'video', 'm4v' => 'video',
    ];

    // 各类型最大文件大小（字节）：图片 10MB、文档 20MB、音视频 50MB
    private static $maxSizes = [
        'image' => 10485760,
        'doc'   => 20971520,
        'audio' => 52428800,
        'video' => 52428800,
    ];

    /**
     * 文件上传
     * POST /api/common/upload
     */
    public function upload(Request $request)
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return json(['code' => 400, 'msg' => '请选择要上传的文件']);
        }
        // 修复：webman UploadFile 在某些平台 getSize() 会触发 stat failed，先用原始 PHP $_FILES 取出 size
        $fileSize = isset($_FILES['file']['size']) ? intval($_FILES['file']['size']) : $file->getSize();
        if (!$fileSize) { return json(['code' => 400, 'msg' => '文件大小读取失败']); }

        // 获取原始文件名和扩展名并校验
        $originalName = $file->getUploadName();
        $ext = strtolower($file->getUploadExtension());
        if (!in_array($ext, self::$allowedExts)) {
            return json(['code' => 400, 'msg' => '不支持的文件类型，允许：图片(jpg/png/gif/webp)、文档(pdf/doc/xls/zip)、音频(mp3/wav)、视频(mp4/webm)']);
        }

        // 修复：按文件类型分别限制大小（图片 10MB / 文档 20MB / 音视频 50MB）
        $bizType = self::$typeMap[$ext] ?? 'doc';
        $maxBytes = self::$maxSizes[$bizType] ?? 10485760;

        // 校验文件大小
        if ($fileSize > $maxBytes) {
            $maxMB = round($maxBytes / 1048576, 1);
            return json(['code' => 400, 'msg' => "文件大小不能超过 {$maxMB}MB（{$bizType} 类型）"]);
        }

        // 生成保存路径：public/uploads/YYYY/MM/DD/unixid.ext（按日期分目录）
        $uploadDir = public_path() . '/uploads/' . date('Y/m/d');
        $newFilename = date('Ymd') . '_' . uniqid() . '.' . $ext;
        $targetPath = $uploadDir . '/' . $newFilename;

        // 移动文件（move 方法会自动创建目录）
        $file->move($targetPath);

        // 返回访问 URL（按 type 字段返回便于前端识别渲染）
        $url = '/uploads/' . date('Y/m/d') . '/' . $newFilename;
        $fileMeta = [
            'url'          => $url,
            'filename'     => $newFilename,
            'original_name'=> $originalName,
            'size'         => $fileSize,
            'ext'          => $ext,
            'type'         => $bizType,    // image / audio / video / doc
        ];
        // 视频/音频返回时长需要 ffmpeg；这里仅在文件名中带前缀时回填，便于前端占位
        return json([
            'code' => 0,
            'msg'  => '上传成功',
            'data' => $fileMeta,
        ]);
    }
}
