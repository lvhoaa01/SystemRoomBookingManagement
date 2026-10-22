<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\UserRepository;
use App\Shared\Security\TokenGenerator;
use App\Shared\Validation\ValidationResult;
use PDOException;
use RuntimeException;

final readonly class RegisterCustomerService
{
    public function __construct(
        private UserRepository $users,
        private TokenGenerator $tokens,
    ) {
    }

    public function register(
        string $fullName,
        string $email,
        ?string $phone,
        string $password,
        string $passwordConfirmation,
    ): RegisterCustomerResult {
        $fullName = preg_replace('/\s+/u', ' ', trim($fullName)) ?? trim($fullName);
        $email = strtolower(trim($email));
        $phone = $this->normalizePhone($phone);

        $validation = $this->validate($fullName, $email, $phone, $password, $passwordConfirmation);
        if (!$validation->isValid()) {
            return RegisterCustomerResult::invalid($validation->errors());
        }

        if ($this->users->emailExists($email)) {
            return RegisterCustomerResult::invalid([
                'email' => ['Email này đã được sử dụng.'],
            ]);
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                $account = $this->users->createCustomer(
                    'KH' . strtoupper(substr($this->tokens->generate(), 0, 12)),
                    $fullName,
                    $email,
                    $phone,
                    $passwordHash,
                );
                return RegisterCustomerResult::success($account->toCurrentUser());
            } catch (PDOException $exception) {
                if (!$this->isUniqueViolation($exception)) {
                    throw $exception;
                }
                if ($this->users->emailExists($email)) {
                    return RegisterCustomerResult::invalid([
                        'email' => ['Email này đã được sử dụng.'],
                    ]);
                }
            }
        }

        throw new RuntimeException('Không thể cấp mã khách hàng duy nhất.');
    }

    private function validate(
        string $fullName,
        string $email,
        ?string $phone,
        string $password,
        string $passwordConfirmation,
    ): ValidationResult {
        $errors = [];
        $nameLength = mb_strlen($fullName);
        if ($nameLength < 2 || $nameLength > 150) {
            $errors['fullName'][] = 'Họ tên phải có từ 2 đến 150 ký tự.';
        }
        if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'][] = 'Email không hợp lệ.';
        }
        if ($phone !== null && preg_match('/^\+?[0-9]{8,15}$/', $phone) !== 1) {
            $errors['phone'][] = 'Số điện thoại phải có từ 8 đến 15 chữ số.';
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            $errors['password'][] = 'Mật khẩu phải có từ 8 đến 72 ký tự.';
        } elseif (preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/[0-9]/', $password) !== 1) {
            $errors['password'][] = 'Mật khẩu phải có ít nhất một chữ cái và một chữ số.';
        }
        if (!hash_equals($password, $passwordConfirmation)) {
            $errors['passwordConfirmation'][] = 'Mật khẩu nhập lại không khớp.';
        }

        return $errors === [] ? ValidationResult::valid() : ValidationResult::invalid($errors);
    }

    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        return preg_replace('/[\s.()-]+/', '', trim($phone)) ?? trim($phone);
    }

    private function isUniqueViolation(PDOException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        return $sqlState === '23000' && $driverCode === 1062;
    }
}
