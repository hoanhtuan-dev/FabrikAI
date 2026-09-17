<?php

namespace App\Notifications;

use App\Models\Generation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * [Đợt 1.5 — 2026-09-17] Thông báo khi một generation (ảnh/video) hoàn tất.
 *
 * Trước đây người dùng phải CANH MÀN HÌNH suốt 3–8 phút render vì app không gửi gì.
 * Nay job gửi email khi kết quả sẵn sàng. (Database channel + Zalo/Telegram để sau.)
 */
class GenerationCompleted extends Notification
{
    use Queueable;

    public function __construct(public Generation $generation) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $label = $this->generation->type === 'video' ? 'Video' : 'Ảnh';
        $demoNote = $this->generation->is_demo
            ? 'Lưu ý: đây là kết quả MẪU (chưa cấu hình API key), không phải ảnh do AI tạo.'
            : null;

        $mail = (new MailMessage)
            ->subject($label.' của bạn đã xong — FabrikAI')
            ->greeting('Xin chào!')
            ->line('Thiết kế '.strtolower($label).' của bạn đã hoàn tất và sẵn sàng để xem.');

        if ($demoNote !== null) {
            $mail->line($demoNote);
        }

        return $mail
            ->action('Xem kết quả', url('/studio'))
            ->line('Cảm ơn bạn đã dùng FabrikAI.');
    }
}
