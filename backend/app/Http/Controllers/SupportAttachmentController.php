<?php

namespace App\Http\Controllers;

use App\Models\SupportTicketReply;
use Illuminate\Support\Facades\Storage;

/**
 * Support ticket attachment download. Authorised by a short-lived relative
 * signature minted only for people who can already see the ticket (see
 * SupportTicketReply::attachmentUrl) — works for the web account, the app
 * and the Staff panel alike.
 */
class SupportAttachmentController extends Controller
{
    public function __invoke(SupportTicketReply $reply)
    {
        $disk = Storage::disk(SupportTicketReply::ATTACHMENT_DISK);
        abort_unless($reply->attachment_path && $disk->exists($reply->attachment_path), 404);

        $inline = $reply->isImage() || $reply->attachment_mime === 'application/pdf';

        return $disk->response(
            $reply->attachment_path,
            $reply->attachment_name,
            ['Content-Type' => $reply->attachment_mime ?: 'application/octet-stream', 'Cache-Control' => 'private, max-age=3600'],
            $inline ? 'inline' : 'attachment',
        );
    }
}
