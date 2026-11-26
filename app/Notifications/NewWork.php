<?php

namespace App\Notifications;

use App\Support\NotificationPresenter;
use App\Support\Preferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A work's first release: a new novel, story, poem, essay or collection, "A new poem: Tin Roof".
 */
class NewWork extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $preference  The reader's switch that decides how this reaches them: chapters for followers, new for everyone else.
     * @param  bool  $mail  False when it should only reach the site (seeded history).
     */
    public function __construct(public int $workId, public string $preference, public bool $mail = true) {}

    /**
     * The site when the reader's site switch is on; email only when it is on and the work still shows.
     *
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        $switches = Preferences::notificationsFor($notifiable);
        $channels = [];

        if ($switches[$this->preference.'.site']) {
            $channels[] = 'database';
        }

        if ($this->mail && $switches[$this->preference.'.email'] && NotificationPresenter::present($this->toArray($notifiable)) !== null) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * @return array{kind: string, work_id: int}
     */
    public function toArray(mixed $notifiable): array
    {
        return ['kind' => 'work', 'work_id' => $this->workId];
    }

    /**
     * Only sent when via() kept the mail channel, so the work still shows.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $message = NotificationPresenter::present($this->toArray($notifiable));

        return (new MailMessage)
            ->subject($message['title'])
            ->view(['mail.notification', 'mail.notification-text'], [
                'title' => $message['title'],
                'small' => $message['small'],
                'href' => $message['href'],
                'buttonText' => 'Read it',
            ]);
    }
}
