<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Payments\Domain\Invoice;
use App\Modules\Payments\Infrastructure\InvoiceRepository;
use App\Shared\Http\NotFoundHttpException;
use App\Shared\Http\ViewRenderer;

final readonly class InvoiceController
{
    public function __construct(
        private InvoiceRepository $invoices,
        private IdentitySession $identity,
        private SessionBootstrap $session,
        private ViewRenderer $views,
        private AppConfig $config,
    ) {
    }

    public function customerShow(Request $request): Response
    {
        $user = $this->identity->currentUser();
        $invoice = $user === null ? null : $this->invoices->findForCustomer($this->code($request), $user->id);
        return $this->render($invoice, 'customer');
    }

    public function partnerShow(Request $request): Response
    {
        $organization = $this->identity->organization();
        $invoice = $organization === null
            ? null
            : $this->invoices->findForOrganization($this->code($request), $organization->id);
        return $this->render($invoice, 'partner');
    }

    private function render(?Invoice $invoice, string $audience): Response
    {
        if (!$invoice instanceof Invoice) {
            throw new NotFoundHttpException();
        }
        $user = $this->identity->currentUser();
        return Response::html($this->views->render('payments/invoice', [
            'title' => 'Hóa đơn ' . $invoice->number,
            'invoice' => $invoice,
            'audience' => $audience,
            'baseUrl' => rtrim($this->config->url, '/'),
            'csrfToken' => $this->session->csrfToken(),
            'flashes' => [],
            'currentUserName' => $user?->fullName,
            'currentUserRole' => $user?->role,
            'activeNavigation' => 'bookings',
        ], 'layouts/app'))
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function code(Request $request): string
    {
        $code = strtoupper(trim((string) $request->route('code', '')));
        if (preg_match('/^[A-Z0-9-]{1,24}$/', $code) !== 1) {
            throw new NotFoundHttpException();
        }
        return $code;
    }
}
