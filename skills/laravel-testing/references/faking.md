# Laravel Faking Reference

## The Golden Rule

Always call the fake BEFORE the action that triggers it. Faking after the trigger means the real implementation already ran — assertions will silently pass or fail incorrectly.

```php
// Correct
Mail::fake();
$this->postJson('/api/register', $payload);
Mail::assertSent(WelcomeEmail::class);

// Wrong — email already sent before fake was registered
$this->postJson('/api/register', $payload);
Mail::fake();
Mail::assertSent(WelcomeEmail::class); // always fails
```

---

## `Mail::fake()`

Intercepts all outgoing mail. No real emails are sent.

```php
Mail::fake();

$this->postJson('/api/register', ['email' => 'ada@test.com', ...])
    ->assertCreated();

// Assert a mailable was sent
Mail::assertSent(WelcomeEmail::class);

// Assert with a condition on the mailable
Mail::assertSent(WelcomeEmail::class, fn (WelcomeEmail $mail) =>
    $mail->hasTo('ada@test.com')
);

// Assert sent to a specific user
Mail::assertSent(WelcomeEmail::class, fn ($mail) =>
    $mail->hasTo($user->email) && $mail->hasCc('admin@test.com')
);

// Assert a queued mailable (Mail::to(...)->queue())
Mail::assertQueued(WelcomeEmail::class);

// Assert a specific count
Mail::assertSent(WelcomeEmail::class, 2);

// Assert nothing was sent
Mail::assertNothingSent();

// Assert a specific mailable was NOT sent
Mail::assertNotSent(WelcomeEmail::class);
```

---

## `Queue::fake()`

Intercepts all queued jobs. No real jobs are pushed to the queue.

```php
Queue::fake();

$this->postJson('/api/checkout', ['cart_id' => 1])->assertOk();

// Assert a job was pushed
Queue::assertPushed(ProcessInvoice::class);

// Assert pushed with a condition
Queue::assertPushed(ProcessInvoice::class, fn (ProcessInvoice $job) =>
    $job->invoiceId === 42
);

// Assert pushed onto a specific queue
Queue::assertPushedOn('invoices', ProcessInvoice::class);

// Assert pushed a specific number of times
Queue::assertPushed(ProcessInvoice::class, 1);

// Assert nothing was pushed
Queue::assertNothingPushed();

// Assert a job was NOT pushed
Queue::assertNotPushed(SendReminder::class);
```

To fake only specific jobs and let others run normally:

```php
Queue::fake([ProcessInvoice::class]);
```

---

## `Event::fake()`

Intercepts all event dispatches. Listeners do not run.

```php
Event::fake();

$this->postJson('/api/orders', $payload)->assertCreated();

// Assert an event was dispatched
Event::assertDispatched(OrderPlaced::class);

// Assert with a condition on the event
Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $event) =>
    $event->order->total === 250
);

// Assert dispatched a specific number of times
Event::assertDispatched(OrderPlaced::class, 1);

// Assert a listener is registered for an event
Event::assertListening(OrderPlaced::class, SendOrderConfirmation::class);

// Assert nothing was dispatched
Event::assertNothingDispatched();

// Assert NOT dispatched
Event::assertNotDispatched(OrderCancelled::class);
```

### Partial Event Fakes

Fake only specific events and let all others propagate normally to their listeners:

```php
Event::fake([OrderPlaced::class]);
// Only OrderPlaced is intercepted — all other events fire normally
```

---

## `Notification::fake()`

Intercepts all notifications. No real notifications (email, SMS, Slack, etc.) are sent.

```php
Notification::fake();

$this->postJson('/api/register', $payload)->assertCreated();

// Assert sent to a notifiable
Notification::assertSentTo($user, WelcomeNotification::class);

// Assert with a condition on the notification
Notification::assertSentTo(
    $user,
    WelcomeNotification::class,
    fn (WelcomeNotification $notification) =>
        $notification->user->id === $user->id
);

// Assert sent via a specific channel
Notification::assertSentTo($user, WelcomeNotification::class, fn ($n, $channels) =>
    in_array('mail', $channels)
);

// Assert nothing was sent
Notification::assertNothingSent();

// Assert NOT sent
Notification::assertNotSentTo($admin, WelcomeNotification::class);

// Assert sent to multiple notifiables
Notification::assertSentTo([$user1, $user2], WelcomeNotification::class);
```

---

## `Storage::fake()`

Replaces a disk with an in-memory filesystem. No real files are written.

```php
Storage::fake('s3');

$this->postJson('/api/avatar', [
    'photo' => UploadedFile::fake()->image('photo.jpg', 800, 600),
])->assertOk();

// Assert a file exists on the fake disk
Storage::disk('s3')->assertExists('avatars/photo.jpg');

// Assert a file does not exist
Storage::disk('s3')->assertMissing('avatars/old-photo.jpg');
```

Creating fake files for upload:

```php
$image   = UploadedFile::fake()->image('avatar.jpg', 400, 400);
$pdf     = UploadedFile::fake()->create('document.pdf', 500); // 500 KB
$bigFile = UploadedFile::fake()->create('video.mp4', 50000);  // 50 MB
```

---

## `Http::fake()`

Intercepts outgoing HTTP requests made via Laravel's `Http` facade. No real external requests are made.

```php
Http::fake([
    'api.stripe.com/*' => Http::response(['id' => 'ch_123', 'status' => 'succeeded'], 200),
    'api.sendgrid.com/*' => Http::response([], 202),
]);

$response = app(StripeService::class)->charge($user, 100);

Http::assertSent(fn (Request $request) =>
    $request->url() === 'https://api.stripe.com/v1/charges' &&
    $request['amount'] === 100
);
```

Catch-all fallback:

```php
Http::fake(['*' => Http::response(['ok' => true], 200)]);
```

Sequential responses — useful for retry logic:

```php
Http::fake([
    'api.example.com/*' => Http::sequence()
        ->push(['status' => 'pending'], 202)
        ->push(['status' => 'complete'], 200),
]);
```

Simulate connection failure:

```php
Http::fake([
    'api.flaky.com/*' => Http::sequence()
        ->pushStatus(500)
        ->push(['ok' => true], 200),
]);
```

Assert no requests were sent:

```php
Http::assertNothingSent();
```

---

## `Bus::fake()` — Chains and Batches

`Bus::fake()` intercepts command bus dispatches. Use it to test job chains and batches without running them.

```php
Bus::fake();

app(CheckoutService::class)->process($order);

// Assert a chain was dispatched in order
Bus::assertChained([
    ValidateOrder::class,
    ProcessPayment::class,
    SendConfirmation::class,
]);

// Assert a batch was dispatched
Bus::assertBatched(fn (PendingBatch $batch) =>
    $batch->name === 'import-products' &&
    $batch->jobs->count() === 3
);

// Assert a specific job was dispatched (not in a chain)
Bus::assertDispatched(ValidateOrder::class);
Bus::assertNotDispatched(RefundOrder::class);
```

---

## Resetting Fakes Between Tests

When using `RefreshDatabase` or `DatabaseTransactions`, fakes reset automatically between tests — each test starts with a clean fake. No manual teardown is needed.

---

## Partial Fakes vs Full Fakes

| Scenario                                              | Approach                                       |
|-------------------------------------------------------|------------------------------------------------|
| No external services should run                       | Full fake: `Mail::fake()`                      |
| Most events should fire but one needs interception    | Partial: `Event::fake([SpecificEvent::class])` |
| Asserting a notification was NOT sent                 | Full fake: `Notification::fake()`              |
| Only one HTTP endpoint needs stubbing                 | Wildcard: `Http::fake(['api.foo.com/*' => ...])` |

Use partial fakes when tests rely on listeners or other side effects from other events firing normally. Use full fakes when the test only cares about the intercepted class and side effects are undesirable.
