<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http;

use App\Bootstrap\SessionBootstrap;
use App\Config\AppConfig;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Identity\Domain\CurrentUser;
use App\Modules\Identity\Infrastructure\IdentitySession;
use App\Modules\Reports\Application\RevenueSettlementRequest;
use App\Modules\Reports\Application\RevenueSettlementService;
use App\Modules\Reports\Domain\RevenueSettlement;
use App\Shared\Http\FlashBag;
use App\Shared\Http\ViewRenderer;
use DomainException;
use InvalidArgumentException;

final readonly class PartnerRevenueSettlementController
{
    public function __construct(
        private RevenueSettlementService $settlements,
        private IdentitySession $identity,
        private SessionBootstrap $session,
        private ViewRenderer $views,
        private FlashBag $flash,
        private AppConfig $config,
    ) {
    }

    public function index(Request $request): Response
    {
        $organizationId = $this->organizationId();
        return $this->view('reports/settlements/index', [
            'title' => 'Đối soát doanh thu',
            'activeNavigation' => 'settlements',
            'settlements' => $this->settlements->list($organizationId),
            'properties' => $this->settlements->properties($organizationId),
            'defaultFrom' => gmdate('Y-m-01'),
            'defaultTo' => gmdate('Y-m-d'),
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $propertyId = filter_var($request->input('propertyId'), FILTER_VALIDATE_INT);
            $input = new RevenueSettlementRequest(
                is_int($propertyId) ? $propertyId : 0,
                trim((string) $request->input('from', '')),
                trim((string) $request->input('to', '')),
            );
            $settlement = $this->settlements->generate(
                $input,
                $this->organizationId(),
                $this->user()->id,
                $request->requestId,
                $request->clientIp,
            );
            $this->flash->add('success', 'Đã tạo bản nháp đối soát từ các hóa đơn đã phát hành.');
            return Response::redirect($this->url('/partner/settlements/' . $settlement->id), 303);
        } catch (InvalidArgumentException|DomainException $exception) {
            $this->flash->add('info', $exception->getMessage());
            return Response::redirect($this->url('/partner/settlements'), 303);
        }
    }

    public function show(Request $request): Response
    {
        try {
            $settlement = $this->settlements->get($this->id($request), $this->organizationId());
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->flash->add('info', $exception->getMessage());
            return Response::redirect($this->url('/partner/settlements'), 303);
        }
        return $this->view('reports/settlements/show', [
            'title' => 'Chi tiết đối soát #' . $settlement->id,
            'activeNavigation' => 'settlements',
            'settlement' => $settlement,
        ]);
    }

    public function finalize(Request $request): Response
    {
        try {
            $settlement = $this->settlements->finalize(
                $this->id($request),
                $this->organizationId(),
                $this->user()->id,
                $request->requestId,
                $request->clientIp,
            );
            $this->flash->add('success', 'Đã chốt kỳ đối soát. Snapshot từ thời điểm này là bất biến.');
            return Response::redirect($this->url('/partner/settlements/' . $settlement->id), 303);
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->flash->add('info', $exception->getMessage());
            return Response::redirect($this->url('/partner/settlements'), 303);
        }
    }

    public function export(Request $request): Response
    {
        try {
            $settlement = $this->settlements->get($this->id($request), $this->organizationId());
        } catch (DomainException|InvalidArgumentException) {
            return Response::html('Không tìm thấy kỳ đối soát.', 404);
        }
        return Response::binary(
            $this->csv($settlement),
            'text/csv',
            200,
            [
                'Content-Disposition' => 'attachment; filename="staybooking-settlement-' . $settlement->id . '.csv"',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    private function csv(RevenueSettlement $settlement): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new DomainException('Không thể tạo file CSV.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Mã booking', 'Số hóa đơn', 'Thời điểm thanh toán', 'Kênh thu',
            'Tổng tiền', 'Đã hoàn', 'Doanh thu thuần', 'Phát hành lúc']);
        foreach ($settlement->lines as $line) {
            fputcsv($stream, [$line['bookingCode'], $line['invoiceNumber'], $line['paymentTiming'],
                $line['collectionChannel'], $line['total'], $line['refunded'], $line['net'], $line['issuedAt']]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return is_string($csv) ? $csv : '';
    }

    /** @param array<string, mixed> $data */
    private function view(string $template, array $data): Response
    {
        $user = $this->user();
        return Response::html($this->views->render($template, [
            ...$data,
            'baseUrl' => rtrim($this->config->url, '/'),
            'csrfToken' => $this->session->csrfToken(),
            'flashes' => $this->flash->consumeAll(),
            'currentUserName' => $user->fullName,
            'currentUserRole' => $user->role,
        ], 'layouts/app'))->withHeader('Cache-Control', 'no-store, private');
    }

    private function id(Request $request): int
    {
        $id = filter_var($request->route('settlementId'), FILTER_VALIDATE_INT);
        if (!is_int($id) || $id < 1) {
            throw new InvalidArgumentException('Mã kỳ đối soát không hợp lệ.');
        }
        return $id;
    }

    private function organizationId(): int
    {
        return $this->identity->organization()?->id
            ?? throw new DomainException('Phiên tổ chức đối tác không hợp lệ.');
    }

    private function user(): CurrentUser
    {
        return $this->identity->currentUser()
            ?? throw new DomainException('Phiên người dùng không hợp lệ.');
    }

    private function url(string $path): string
    {
        return rtrim($this->config->url, '/') . $path;
    }
}
