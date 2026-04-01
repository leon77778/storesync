<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * We pass the Order into the Mailable so the email template
     * has access to everything it needs: customer name, line items, totals.
     */
    public function __construct(public Order $order) {}

    /**
     * The envelope defines what appears in the email header —
     * the subject line the customer sees in their inbox.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your order {$this->order->order_ref} is confirmed — StoreSync",
        );
    }

    /**
     * The content definition points Laravel at the Blade view
     * that contains the email body HTML.
     * 'order' is passed automatically because it's a public property.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.order-confirmation',
        );
    }
}
