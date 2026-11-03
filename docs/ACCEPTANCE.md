# Biên bản nghiệm thu toàn hệ thống

Biên bản này là nguồn truy vết có thể lặp lại cho giai đoạn 17. Trạng thái `PASSED` chỉ được ghi khi có test tự động
hoặc validator thực thi được. Mốc nghiệm thu: 03/11/2026, PHP 8.2.12, MariaDB 10.4.32.

## Dữ liệu và bộ kiểm thử

- Database được tạo lại từ migration và seed `001`–`006`, đúng 22 bảng.
- `composer run verify`: 53/53 kiểm tra manifest, checksum, số dòng và fixture invalid; 33/33 SQL invariant.
- `composer test`: unit, integration và concurrency suite. Sau suite, `composer run verify` vẫn đạt để chứng minh test
  hoàn trả dữ liệu nền.
- Backup đồng bộ database–uploads được tạo trước khi nghiệm thu; checksum hai artifact khớp manifest backup.

## 13 kịch bản tranh chấp

Mọi worker dùng kết nối PDO riêng. Các test mới dùng cùng `startAt` làm barrier và luôn đối soát counter với ledger.

| # | Kịch bản | Bằng chứng |
|---:|---|---|
| 1 | Hai request tranh unit cuối | [`BookingConcurrencyTest`](../tests/Integration/Modules/Bookings/BookingConcurrencyTest.php) |
| 2 | Double-click cùng `ClientRequestID` | `BookingConcurrencyTest::test_double_click_with_same_client_request_id_returns_one_booking` |
| 3 | Cùng preview, UUID khác | `BookingConcurrencyTest::test_same_preview_with_different_request_ids_creates_only_one_booking` |
| 4 | Payment callback và expiry | [`PaymentRaceAcceptanceTest`](../tests/Concurrency/PaymentRaceAcceptanceTest.php) |
| 5 | Late success sau release | `PaymentRaceAcceptanceTest` và `OnlinePaymentServiceTest::test_expiry_wins_then_late_success_is_refunded_without_reviving_booking` |
| 6 | Cancel và payment finalize | [`LifecycleRaceAcceptanceTest`](../tests/Concurrency/LifecycleRaceAcceptanceTest.php) |
| 7 | Cancel và no-show | `LifecycleRaceAcceptanceTest::test_cancel_and_no_show_allow_only_one_state_transition` |
| 8 | Waiver approve và cancel trực tiếp | `LifecycleRaceAcceptanceTest::test_waiver_approval_and_direct_cancel_release_inventory_once` |
| 9 | Giảm tổng tồn và booking | [`InventoryConcurrencyTest`](../tests/Integration/Modules/Inventory/InventoryConcurrencyTest.php) |
| 10 | Hai đổi ngày khóa chéo | `DateChangeServiceTest::test_two_concurrent_date_changes_finish_with_only_one_inventory_transition` |
| 11 | Booking và sửa rate | [`RateBookingRaceAcceptanceTest`](../tests/Concurrency/RateBookingRaceAcceptanceTest.php) |
| 12 | Completion và no-show | `LifecycleRaceAcceptanceTest::test_completion_and_no_show_allow_only_one_terminal_state` |
| 13 | Counter luôn khớp ledger sau race | Assertion hậu kiểm trong toàn bộ test trên và SQL check `inventory_counter_ledger_mismatch` |

## 60 tiêu chí chấp nhận

| # | Trạng thái | Bằng chứng chính |
|---:|---|---|
| 1 | PASSED | [`SearchHttpFlowTest::test_anonymous_guest_can_search_and_open_detail_without_authentication`](../tests/Integration/Modules/Search/SearchHttpFlowTest.php); `PreviewHttpFlowTest::test_preview_routes_require_customer_role_and_csrf` |
| 2 | PASSED | [`LoginLogoutServiceTest::test_partner_without_membership_logs_in_without_organization_context`](../tests/Integration/Modules/Identity/LoginLogoutServiceTest.php); identity middleware tests |
| 3 | PASSED | [`PropertyRepositoryTest`](../tests/Integration/Modules/Properties/PropertyRepositoryTest.php), `PropertyHttpFlowTest::test_partner_dashboard_renders_only_own_properties_and_blocks_cross_tenant_detail` |
| 4 | PASSED | [`PropertyWorkflowServiceTest::test_not_ready_draft_stays_draft_and_admin_can_request_changes_from_pending`](../tests/Integration/Modules/Properties/PropertyWorkflowServiceTest.php) |
| 5 | PASSED | `PropertyWorkflowServiceTest` xác nhận `PENDING_REVIEW → DRAFT`; [`PropertyStatus`](../src/Modules/Properties/Domain/PropertyStatus.php) không có `NEEDS_CHANGES` |
| 6 | PASSED | [`PropertyReviewActionTest`](../tests/Unit/Modules/Properties/PropertyReviewActionTest.php), `PropertyRepositoryTest::test_transition_rejects_undocumented_workflow` |
| 7 | PASSED | [`SearchCatalogRepositoryTest`](../tests/Integration/Modules/Search/SearchCatalogRepositoryTest.php), đặc biệt test ẩn cơ sở suspended |
| 8 | PASSED | [`SearchCriteriaFactoryTest`](../tests/Unit/Modules/Search/SearchCriteriaFactoryTest.php), `SearchHttpFlowTest::test_invalid_search_input_returns_validation_error` |
| 9 | PASSED | [`SearchServiceTest::test_returns_only_properties_with_a_complete_pure_timing_allocation`](../tests/Integration/Modules/Search/SearchServiceTest.php) |
| 10 | PASSED | [`DeterministicRoomAllocatorTest`](../tests/Unit/Modules/Search/DeterministicRoomAllocatorTest.php), `PropertyAvailabilityServiceTest::test_selected_mapping_is_untrusted_and_mismatch_is_reported` |
| 11 | PASSED | [`PropertyAvailabilityServiceTest::test_detail_matches_search_price_allocation_timing_and_fingerprint_when_snapshot_is_unchanged`](../tests/Integration/Modules/Search/PropertyAvailabilityServiceTest.php) |
| 12 | PASSED | `SearchServiceTest::test_same_snapshot_returns_the_same_order_allocation_and_fingerprint`; `PreviewServiceTest::test_snapshot_factory_rejects_rooms_from_mixed_payment_timings` |
| 13 | PASSED | `PropertyAvailabilityServiceTest::test_rate_version_change_is_reported_instead_of_silently_reusing_the_card` |
| 14 | PASSED | [`PreviewServiceTest`](../tests/Integration/Modules/Bookings/PreviewServiceTest.php), rate/version thay đổi được đọc lại |
| 15 | PASSED | [`PreviewHttpFlowTest`](../tests/Integration/Modules/Bookings/PreviewHttpFlowTest.php), snapshot hiển thị tổng/timing/chính sách/cảnh báo |
| 16 | PASSED | `PreviewServiceTest::test_creates_fifteen_minute_preview_without_holding_inventory`; [`PreviewRepositoryTest`](../tests/Integration/Modules/Bookings/PreviewRepositoryTest.php) |
| 17 | PASSED | `BookingServiceTest::test_stale_preview_rolls_back_without_booking_payment_or_ledger`; preview owner/expiry tests |
| 18 | PASSED | [`BookingServiceTest::test_creates_all_items_and_nights_for_multi_room_booking`](../tests/Integration/Modules/Bookings/BookingServiceTest.php) |
| 19 | PASSED | `BookingServiceTest` rollback và transaction integration |
| 20 | PASSED | `BookingConcurrencyTest::test_two_booking_transactions_competing_for_last_unit_allow_only_one_winner` |
| 21 | PASSED | `BookingServiceTest::test_creates_confirmed_pay_at_property_booking_and_replays_same_request`; double-click race |
| 22 | PASSED | `BookingServiceTest::test_rejects_second_request_for_used_preview_and_conflicting_idempotency_key` |
| 23 | PASSED | `BookingConcurrencyTest`, `InventoryConcurrencyTest`, SQL checks `inventory_negative_or_oversold` và `inventory_counter_ledger_mismatch` |
| 24 | PASSED | SQL checks `active_ledger_bad_booking_state` và `terminal_booking_has_active_ledger` |
| 25 | PASSED | `BookingServiceTest::test_booking_reads_saved_snapshot_after_catalog_name_changes` |
| 26 | PASSED | `BookingServiceTest::test_creates_confirmed_pay_at_property_booking_and_replays_same_request`; payment SQL invariant |
| 27 | PASSED | [`LifecycleAndReviewServiceTest::test_completion_batch_releases_inventory_and_running_again_is_safe`](../tests/Integration/Modules/Bookings/LifecycleAndReviewServiceTest.php); `payment_booking_mismatch = 0` |
| 28 | PASSED | `CancellationServiceTest::test_pay_at_property_cancel_releases_once_and_retry_is_idempotent`; no-show lifecycle test; `ReportServiceTest` |
| 29 | PASSED | [`OnlinePaymentServiceTest`](../tests/Integration/Modules/Bookings/OnlinePaymentServiceTest.php); SQL checks timing split và phí tại cơ sở |
| 30 | PASSED | `BookingHttpFlowTest::test_customer_online_payment_only_shows_success_after_payment_is_paid`; online payment success test |
| 31 | PASSED | `OnlinePaymentServiceTest::test_success_is_finalized_after_gateway_and_makes_hold_permanent` kiểm gateway được gọi ngoài transaction |
| 32 | PASSED | `OnlinePaymentServiceTest::test_timeout_keeps_processing_and_retry_reuses_booking_payment_and_key` |
| 33 | PASSED | `OnlinePaymentServiceTest::test_definite_failure_releases_inventory_exactly_once`; `PaymentRaceAcceptanceTest` |
| 34 | PASSED | `OnlinePaymentServiceTest::test_expiry_wins_then_late_success_is_refunded_without_reviving_booking`; payment race |
| 35 | PASSED | [`CancellationCalculatorTest`](../tests/Unit/Modules/Commercial/CancellationCalculatorTest.php), [`CancellationQuoteServiceTest`](../tests/Integration/Modules/Bookings/CancellationQuoteServiceTest.php) |
| 36 | PASSED | [`CancellationServiceTest`](../tests/Integration/Modules/Bookings/CancellationServiceTest.php); SQL check cancellation metadata |
| 37 | PASSED | `CancellationServiceTest::test_online_refund_failure_keeps_cancelled_and_same_key_can_retry_successfully` |
| 38 | PASSED | `CancellationServiceTest::test_pay_at_property_cancel_releases_once_and_retry_is_idempotent` |
| 39 | PASSED | `CancellationServiceTest::test_provider_amount_mismatch_never_marks_refund_successful` |
| 40 | PASSED | Refund-failure test giữ booking `CANCELLED` nhưng refund chưa thành công |
| 41 | PASSED | [`WaiverServiceTest::test_pending_request_does_not_change_booking_payment_or_inventory`](../tests/Integration/Modules/Bookings/WaiverServiceTest.php) |
| 42 | PASSED | `WaiverServiceTest::test_only_same_organization_can_reject_and_rejection_keeps_booking`; lifecycle tenant test |
| 43 | PASSED | [`DateChangeQuoteServiceTest::test_preview_reprices_without_mutating_booking_or_inventory`](../tests/Integration/Modules/Bookings/DateChangeQuoteServiceTest.php) |
| 44 | PASSED | [`InventoryDateChangeServiceTest`](../tests/Integration/Modules/Inventory/InventoryDateChangeServiceTest.php), [`DateChangeServiceTest`](../tests/Integration/Modules/Bookings/DateChangeServiceTest.php) |
| 45 | PASSED | `DateChangeServiceTest::test_stale_quote_rolls_back_everything` |
| 46 | PASSED | `DateChangeServiceTest::test_change_updates_all_snapshots_inventory_payment_and_is_idempotent` |
| 47 | PASSED | `LifecycleAndReviewServiceTest::test_partner_no_show_releases_inventory_and_is_idempotent` |
| 48 | PASSED | Lifecycle tests và SQL checks terminal/ledger |
| 49 | PASSED | `LifecycleAndReviewServiceTest::test_completion_batch_releases_inventory_and_running_again_is_safe` |
| 50 | PASSED | `LifecycleAndReviewServiceTest::test_completed_customer_can_review_once_and_admin_only_changes_visibility` |
| 51 | PASSED | Lifecycle/review tests và fixture-invalid unique review validator |
| 52 | PASSED | Lifecycle/review test ẩn review; public score query trong [`SearchCatalogRepository`](../src/Modules/Search/Infrastructure/SearchCatalogRepository.php) |
| 53 | PASSED | SQL checks `verified_property_missing_provenance`, `google_metadata_inconsistent`; fixture manifest/source checksum |
| 54 | PASSED | [`ReportServiceTest::test_partner_report_is_tenant_scoped_and_uses_explicit_financial_formulas`](../tests/Integration/Modules/Admin/ReportServiceTest.php) |
| 55 | PASSED | `ReportServiceTest`, [`ReportRepository`](../src/Modules/Admin/Infrastructure/ReportRepository.php) |
| 56 | PASSED | Fixture validator đúng 22 bảng; migration duy nhất [`001_create_schema.sql`](../database/migrations/001_create_schema.sql) |
| 57 | PASSED | [`validate_fixture.php`](../scripts/validation/validate_fixture.php), [`run_integrity.php`](../scripts/validation/run_integrity.php) |
| 58 | PASSED | SQL check `pay_online_nightly_at_property_nonzero = 0` |
| 59 | PASSED | [`SearchQueryPlanTest`](../tests/Integration/Modules/Search/SearchQueryPlanTest.php), [`AdminQueryPlanTest`](../tests/Integration/Modules/Admin/AdminQueryPlanTest.php) |
| 60 | PASSED | DESCRIPTION/DESIGN audit, module README ownership và toàn bộ SQL/contract test ở trên |

## Route, tài liệu và ownership

- Route thực thi nằm trong [`src/routes`](../src/routes); các route ở DESIGN là contract ngữ nghĩa, còn test HTTP khóa auth,
  CSRF, tenant scope và response thực tế.
- Transaction/lock order triển khai khớp sequence: booking mới khóa preview trước; booking hiện hữu khóa booking → payment
  → ledger → inventory; provider luôn được gọi sau commit.
- README cấp module ghi bảng sở hữu và ranh giới. Bookings sở hữu state transition; Inventory sở hữu counter/ledger;
  Search chỉ đọc; Admin chỉ đọc báo cáo/audit và điều phối đúng module chủ quản.
- Hướng dẫn cài đặt, import, tài khoản fixture, validator và console job nằm tại [`README.md`](../README.md); backup/restore
  tại [`OPERATIONS.md`](OPERATIONS.md).
