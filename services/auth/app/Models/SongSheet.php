<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * SongSheet model (SRS 6.1, FR5).
 *
 * A file attachment on a song — typically a PDF lead sheet or an image
 * scan of musical notation. The actual file lives in MinIO (object
 * storage). We persist only metadata + the bucket path; the download URL
 * is generated on demand as a short-lived presigned S3 URL.
 *
 * @property string      $id
 * @property string      $song_id
 * @property string      $original_filename
 * @property string      $storage_disk
 * @property string      $storage_path
 * @property string      $file_type     'pdf' | 'image'
 * @property string      $mime_type
 * @property int         $size_bytes
 * @property string|null $uploaded_by
 */
class SongSheet extends Model
{
    /** @use HasFactory<\Database\Factories\SongSheetFactory> */
    use HasFactory;
    use HasUuids;

    public const TYPE_PDF = 'pdf';

    public const TYPE_IMAGE = 'image';

    protected $fillable = [
        'song_id',
        'original_filename',
        'storage_disk',
        'storage_path',
        'file_type',
        'mime_type',
        'size_bytes',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Song, $this> */
    public function song(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Song::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function uploader(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
