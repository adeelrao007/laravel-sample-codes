<?php

namespace App\Observers;

use App\Jobs\SendGiftCardJob;
use App\Mail\GiftCard\RedeemGiftCard;
use App\Mail\GiftCard\SendGiftCard;
use App\Models\UserGiftCard;
use Exception;
use Illuminate\Support\Facades\Mail;

class UserGiftCardObserver
{
    /**
     * Handle events after all transactions are committed.
     *
     * @var bool
     */
    public bool $afterCommit = true;

    /**
     * Handle the UserGiftCard "created" event.
     *
     * @param  \App\Models\UserGiftCard  $userGiftCard
     * @return void
     */
    public function created(UserGiftCard $userGiftCard)
    {
        if ((string) $userGiftCard->giftcard_status === UserGiftCard::ACTIVE && (int) $userGiftCard->is_redeemed === UserGiftCard::IS_REDEEMED) {
            $email = $userGiftCard->user->email;
            $username = $userGiftCard->user->username;
            $giftCardAmount = number_format($userGiftCard->giftcard_amount / 100, 2);
            $userGiftCard->load('giftCardOrder.order.paymentMethod');

            try {
                Mail::queue(new RedeemGiftCard(
                    $giftCardAmount,
                    $username,
                    $email,
                    $userGiftCard->giftcard_orderid,
                    $userGiftCard->card->image_name,
                    $userGiftCard->giftCardOrder->order->paymentMethod
                ));
            } catch (Exception $exception) {
                report($exception->getMessage());
            }
        }
    }

    /**
     * Handle the UserGiftCard "updated" event.
     *
     * @param  \App\Models\UserGiftCard  $userGiftCard
     * @return void
     */
    public function updated(UserGiftCard $userGiftCard)
    {
        if ((string) $userGiftCard->giftcard_status === UserGiftCard::ACTIVE && (int) $userGiftCard->is_redeemed !== UserGiftCard::IS_REDEEMED) {
            $userGiftCard->load('user');
            $userGiftCard->load('card');
            $users = explode(',', $userGiftCard->to);

            $giftCardAmount = number_format($userGiftCard->giftcard_amount / 100, 2);
            $from = $userGiftCard->user->username;
            $code = $userGiftCard->giftcard_number;
            $pin = $userGiftCard->pin;

            try {
                // Create a queue job for send giftcard emails
                dispatch(new SendGiftCardJob($users, $from, $giftCardAmount, $code, $pin, $userGiftCard->card->image_name, $userGiftCard->giftcard_orderid));
            } catch (Exception $exception) {
                report($exception);
            }
        }
    }
}
