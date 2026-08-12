# アーキテクチャ / MVC 設計 — pato岡山（仮）

- 構成: **Laravel 11 モノリス（コアサーバ）+ Blade サーバレンダリング + PWA**
- 方針: 「薄いController / 厚いService」。ドメインごとに凝集。

---

## 1. レイヤー構成

```
Request
  │
  ▼
[Controller] ── 入出力のみ。業務判断を持たない
  │  ├─ [FormRequest]  入力バリデーション・認可の入口
  │  └─ [Policy]       認可（所有権・ロール）
  ▼
[Service / Action] ── 業務ロジックの本体。トランザクション境界
  │  ├─ [DTO]         レイヤー間の受け渡し
  │  ├─ [Model(Eloquent)] 永続化・リレーション
  │  ├─ [Event/Listener]  副作用（通知・監査）を疎結合に
  │  └─ [Job(Queue)]      非同期（プッシュ、精算計算など）
  ▼
[Adapter] ── 外部依存（決済PSP / eKYC / プッシュ）を抽象化
  ▼
外部サービス / DB / Redis
```

- **Controller**: リクエストを受け、Service を呼び、View/Resource を返すだけ。
- **Service**: 1ユースケース = 1メソッド（または1 Action クラス）。DBトランザクションは
  ここで張る。ドメインルールの唯一の置き場所。
- **Model**: リレーションとスコープ中心。業務ルールをモデルに詰め込みすぎない。
- **Policy**: `guest`/`cast`/`admin` と所有権の認可。Controller に `if` を散らさない。
- **Adapter/Contract**: `PaymentGateway`, `EkycProvider`, `PushSender` をインターフェースで
  定義し、実装を差し替え可能に（テストでは Fake）。

---

## 2. MVC の対応

| MVC | Laravel での実体 | 例 |
|---|---|---|
| Model | Eloquent Model + Service（ドメイン層） | `Call`, `PointLedger`, `CastProfile` |
| View | Blade テンプレート + Vite アセット | `resources/views/calls/create.blade.php` |
| Controller | HTTP Controller（薄い） | `CallController@store` |

補助:
- **FormRequest** = 入力の門番（バリデーション/認可）
- **Resource** = 出力整形（PWAのfetch/JSON用）
- **Service/Action** = Fat Model の代わりの業務ロジック層

---

## 3. ディレクトリ構造

```
app/
  Http/
    Controllers/
      Guest/           CallController, PointController, ...
      Cast/            ScreeningController, ParticipationController, PayoutController
      Admin/           ScreeningReviewController, ReportController, MasterController
    Requests/          CreateCallRequest, ParticipateRequest, ...
    Resources/         CallResource, WalletResource, ...
    Middleware/        EnsureIdentityVerified, EnsureInOkayamaArea
  Domain/
    Call/
      Services/        CreateCallService, MatchCallService, CompleteCallService
      Actions/         ExtendCallAction, CancelCallAction
      DTO/             CallDraft, CallResultDTO
    Cast/
      Services/        SubmitScreeningService, ApproveScreeningService
    Guest/
      Services/        RegisterGuestService
    Point/
      Services/        PurchasePointService, HoldPointService, CapturePointService
      Support/         PointBalance（台帳合算ロジック）
    Payout/
      Services/        AccruePayoutService, RequestPayoutService, ApprovePayoutService
    Messaging/
      Services/        PostMessageService（NG検知含む）
    Trust/
      Services/        SubmitReportService, VerifyIdentityService
      Support/         ContentFilter
  Models/              User, GuestProfile, CastProfile, Call, CallParticipant,
                       PointTransaction, PointProduct, Payout, Thread, Message,
                       Review, Report, IdentityVerification, Area, Venue, ClassTier
  Policies/            CallPolicy, ThreadPolicy, PayoutPolicy
  Jobs/                SendPushJob, SettleCallJob, ReleaseExpiredHoldJob
  Events/ Listeners/
  Support/
    Contracts/         PaymentGateway, EkycProvider, PushSender
    Adapters/          StripePaymentGateway, XxxEkycProvider, WebPushSender
resources/views/       Blade（guest / cast / admin / components）
routes/                web.php, admin.php, api.php（PWA fetch 用の最小API）
database/
  migrations/
  seeders/             AreaSeeder（岡山エリア）, ClassTierSeeder, PointProductSeeder
tests/
  Feature/             ユースケース単位（CreateCall, Matching, PointHold...）
  Unit/                PointBalance, ContentFilter...
```

---

## 4. 中核ドメインの状態遷移

### Call（patoコール）
```
draft → open → matched → in_progress → completed
                   │           │
                   ├→ canceled ┤
                   └→ expired（定員未達で期限切れ）
```
- 遷移は必ず対応する Service を通す（`MatchCallService` など）。
- 各遷移で Event を発行し、通知・台帳・精算の副作用を Listener/Job で処理。

### Point（台帳・与信モデル）
```
purchase(+)  ── 有償/無償ポイント付与
hold(-)      ── 呼び出し作成/延長時に与信ホールド
capture      ── 完了時にホールドを確定消費
release(+)   ── キャンセル/不成立でホールド解放
expire(-)    ── 180日到達で失効
tip(-)       ── おひねり消費
```
- 残高 = `sum(signed_amount)`。ホールドは「利用可能残高」計算で控除。
- **残高カラムを直接更新しない**（監査・整合のため台帳一本化）。

### Screening（審査）
```
applied → photo_review → interview → approved / rejected
```

### Payout（精算）
```
accrued → requested → approved → paid
                          └→ rejected
```

---

## 5. 横断的関心事

- **認可**: Policy。`EnsureIdentityVerified` / `EnsureInOkayamaArea` ミドルウェアで
  未確認・エリア外を入口で弾く。
- **非同期**: プッシュ通知・精算計算・期限切れホールド解放は Queue(Job)。
- **監査ログ**: ポイント/精算/制裁は追記型ログ。
- **PII保護**: 本人確認書類は暗号化・保持期間管理。ログにPIIを出さない。
- **外部依存の抽象化**: PSP/eKYC/Push は Contracts 経由。テストは Fake 実装で差し替え。

---

## 6. テスト戦略（ハーネスと対応）

- **Feature テスト**でユースケース（呼び出し作成→成立→完了→精算）を通す。
- **Unit テスト**で台帳合算・与信計算・NG検知など純粋ロジックを固める。
- 外部（PSP/eKYC/Push）は Fake。実ベンダは契約テストで別途。
- 詳細は `docs/05_dev_harness.md`。
