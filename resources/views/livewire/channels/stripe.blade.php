<?php

use function \Livewire\Volt\{state, mount, computed};

$cache = cache()->driver('database');

state([
    'name' => 'Stripe ' . request('id'),
    'sessionId' => request('id'),
    'secret' => '',
    'log' => [],
    'amount' => 1,
    'view' => 'logs'
]);

$client = computed(function () {
    return new \Stripe\StripeClient($this->secret);
});
$paid = computed(function () {
    if (!$this->pollUrl) return false;

    $status = $this->client->pollTransaction(
        $this->pollUrl
    );

    return $status->paid();
});
$code = computed(function () {
    return highlight_string(<<<PHP
<?php

\$client = new \Stripe\StripeClient('$this->secret');

\$session = \$client->checkout->sessions->create([
    'line_items' => [[
        'price_data' => [
            'currency' => 'USD',
            'unit_amount' => $this->amount * 100,
            'product_data' => [
                'name' => 'Belgravia London Dry',
            ]
        ],
        'quantity' => 1,
    ]],
    'mode' => 'payment',
    'success_url' => route('stripe.success'),
    'cancel_url' => route('stripe.cancelled'),
]);
PHP, true);
});

$write = function ($info, $color = 'default') {
    $this->log[] = [
        'time' => now()->toDateTimeString(),
        'log' => $info,
        'class' => match ($color) {
            'success' => 'text-green-500',
            'error' => 'text-red-500',
            'warn' => 'text-amber-500',
            'info' => 'text-blue-500',
            default => ''
        }
    ];
};
$pay = function () {
    $invoiceName = 'Invoice ' . rand();

    $this->write("Initiating payment for invoice $invoiceName for $" . number_format($this->amount, 2));

    $client = $this->client;

    $session = $client->checkout->sessions->create([
        'line_items' => [[
            'price_data' => [
                'currency' => 'USD',
                'unit_amount' => $this->amount * 100,
                'product_data' => [
                    'name' => $invoiceName,
                ]
            ],
            'quantity' => 1,
        ]],
        'mode' => 'payment',
        'success_url' => route('channels.stripe', [
            'id' => $this->sessionId,
            'success' => true
        ]),
        'cancel_url' => route('channels.stripe', $this->sessionId),
    ]);

    $this->write('<details><summary>View response json</summary><pre><code>'. json_encode($session, JSON_PRETTY_PRINT) .'</code></pre></details>');
    $this->write("Complete payment at <a target=\"_blank\" href=\"$session->url\">$session->url</a>", 'success');
    $this->save('Autosave -');
};
$save = function ($trigger = '', $silent = false) use ($cache) {
    if (!$silent) {
        $this->write("$trigger Saving session [$this->sessionId]");
    }

    session()->put('activeSessionId', $this->sessionId);
    $cache->put($this->sessionId, [
        'user_id' => auth()->id(),
        ...$this->all()
    ]);

    $user = auth()->user();
    $user->sessions = [
        $this->name => [
            'id' => $this->sessionId,
            'route' => 'channels.stripe'
        ],
        ...$user->sessions ?? []
    ];
    $user->save();
};
$clear = function () {
    $this->log = [];
    $this->save(silent: true);
};
$newSession = function ($id = null) {
    $this->redirect(
        route(
            'channels.stripe',
            $id ?? \Illuminate\Support\Str::random()
        )
    );
};

mount(function () use ($cache) {
    if (!request('id')) {
        $this->newSession();
        return;
    }

    $restore = $cache->get($this->sessionId) ?? [];

    if (empty($restore)) {
        $this->write('Welcome to stripe playground, ' . auth()->user()->email);
    } else {
        $this->fill($restore);
    }

    if (request('success')) {
        $this->write('Last transaction was paid successfully', 'success');
    }
});
?>


<div class="flex h-[90vh] w-full flex-1 flex-col gap-4 rounded-xl">
    <div class="grid auto-rows-min gap-4 md:grid-cols-2">
        <div
            class="relative p-4 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <flux:input label="{{ __('Session Name') }}" wire:model="name"/>
        </div>
        <div
            class="relative p-4 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <flux:input label="{{ __('Secret Key') }}" wire:model="secret" placeholder="sk_test_.."/>
        </div>
    </div>

    <div
        class="relative h-full flex-1 flex flex-col overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <pre
            class="p-4 border-b border-neutral-200 dark:border-neutral-700"><code>composer require stripe/stripe-php</code></pre>
        <div class="grow overflow-auto">
            <div class="flex space-x-4 p-4 border-b border-neutral-200 dark:border-neutral-700">
                <flux:button size="sm" wire:click="$set('view', 'logs')">{{ __('View Logs') }}</flux:button>
                <flux:button size="sm" wire:click="$set('view', 'code')">{{ __('View Code') }}</flux:button>
                <flux:button icon-trailing="x-mark" size="sm" wire:click="clear">
                    {{ __('Clear log') }}
                </flux:button>
            </div>
            @if($view == 'logs')
                @forelse(collect($log)->reverse() as $item)
                    <div class="mb-3 p-4">
                        <small class="opacity-40 block">{{ $item['time'] }}</small>
                        <span class="{{ $item['class'] }}">{!! $item['log'] !!}</span>
                    </div>
                @empty
                    <div class="h-full flex items-center justify-center flex-col opacity-50">
                        <flux:icon.adjustments-horizontal class="w-44 h-44"/>
                        {{ __('Empty log') }}
                    </div>
                @endforelse
            @elseif($view == 'code')
                <div
                    class="flex items-center bg-white w-full overflow-auto font-mono space-x-4 p-4 border-t border-neutral-200 dark:border-neutral-700">
                    <pre>{!! $this->code !!}</pre>
                </div>
            @endif
        </div>
        <div class="flex space-x-4 p-4 border-t border-neutral-200 dark:border-neutral-700">
            <flux:input wire:model="amount" type="number" label="{{ __('Amount ($)') }}" placeholder="Enter amount..."/>
        </div>
        <div wire:poll.visible.5s class="p-4 border-t border-neutral-200 dark:border-neutral-700">
            <flux:button wire:click="pay">Submit Payment</flux:button>
            <flux:button wire:click="save">Save Session</flux:button>
            <flux:button wire:click="newSession">New Session</flux:button>
        </div>
    </div>
</div>


