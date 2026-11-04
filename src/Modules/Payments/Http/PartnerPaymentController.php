<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http;

use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Bookings\Application\BookingException;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Payments\Application\AtPropertyPaymentRequest;
use App\Modules\Payments\Application\RecordAtPropertyPaymentService;
use App\Shared\Http\FlashBag;
use App\Shared\Http\NotFoundHttpException;
use InvalidArgumentException;

final readonly class PartnerPaymentController
{
    public function __construct(
        private RecordAtPropertyPaymentService $payments,
        private IdentitySession $identity,
        private FlashBag $flash,
        private AppConfig $config,
    ) {
    }

    public function record(Request $request): Response
    {
        $code = strtoupper(trim((string) $request->route('code', '')));
        $organization = $this->identity->organization();
        $user = $this->identity->currentUser();
        if ($organization === null || $user === null) {
            throw new NotFoundHttpException();
        }

        try {
            $result = $this->payments->record(
                $code,
                $organization->id,
                $user->id,
                AtPropertyPaymentRequest::fromInput($request->body),
                $request->clientIp,
            );
            $this->flash->add(
                'success',
                'Đã ghi nhận ' . number_format((float) $result->amount->decimal(), 0, ',', '.')
                . ' ₫ tại cơ sở.',
            );
        } catch (BookingException $exception) {
            if ($exception->errorCode === 'BOOKING_NOT_FOUND') {
                throw new NotFoundHttpException();
            }
            $this->flash->add('info', $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            $this->flash->add('info', $exception->getMessage());
        }

        return Response::redirect(
            rtrim($this->config->url, '/') . '/partner/bookings/' . rawurlencode($code),
            303,
        );
    }
}
