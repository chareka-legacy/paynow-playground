<?php

use function \Livewire\Volt\{state, mount, computed};

$cache = cache()->driver('database');

state([
    'name' => auth()->user()->name,
    'sessionId' => request('id'),
    'integrationKey' => '',
    'integrationId' => '',
    'integrationEmail' => null,
    'log' => [],

    'amount' => 1,
    'phone' => '',
    'method' => 'EcoCash',

    'pollUrl' => null
]);

$paynow = computed(function () {
    return new Paynow\Payments\Paynow(
        $this->integrationId,
        $this->integrationKey,
        'http://example.com/gateways/paynow/update',
        'http://example.com/return?gateway=paynow'
    );
});
$paid = computed(function () {
    if (!$this->pollUrl) return false;

    $status = $this->paynow->pollTransaction(
        $this->pollUrl
    );

    return $status->paid();
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

    $paynow = $this->paynow;
    $payment = $paynow->createPayment($invoiceName, $this->integrationEmail ?? 'user@example.com');

    $payment->add('Test', $this->amount);

    $method = Str::startsWith( $this->method, 'Paynow')
        ? 'paynow'
        : strtolower($this->method);

    $response = $method == 'paynow'
        ? $paynow->send($payment)
        : $paynow->sendMobile($payment, $this->phone, $method);

    if ($response->success()) {
        if($method == 'paynow'){
            $this->write('Payment ready on ' . $response->redirectUrl(), 'success');
        } else {
            $this->write('Payment instructions: ' . $response->instructions(), 'success');
        }

        $this->pollUrl = $response->pollUrl();
        $this->check();
    } else {
        $this->write('Failed to make payment: ' . $response->errors(), 'error');
    }

    $this->save('Autosave -');
};
$save = function ($trigger = '', $silent = false) use ($cache) {
    if(!$silent) {
        $this->write("$trigger Saving session [$this->sessionId]");
    }

    session()->put('activeSessionId', $this->sessionId);
    $cache->put($this->sessionId, $this->all());

    $user = auth()->user();
    $user->sessions = [
        $this->name => $this->sessionId,
        ...$user->sessions ?? []
    ];
    $user->save();
};
$check = function () {
    $paid = $this->paid;
    $this->write(
        'Last transaction was ' . ($paid ? 'paid successfully' : 'not paid'),
        $paid ? 'success' : 'warn'
    );
};
$clear = function () {
    $this->log = [];
    $this->save(silent: true);
};
$live = function () {
    $this->clear();
    $this->write('Running mobile live transactions', 'warn');

    foreach (['ecocash', 'onemoney', 'telecash'] as $method) {
        $this->method = $method;
        $this->write('Running mobile live transactions: ' . $method, 'warn');

        $this->write('Running success: 0771111111', 'info');
        $this->phone = '0771111111';
        $this->pay();

        $this->write('Running delayed success: 0772222222', 'info');
        $this->phone = '0772222222';
        $this->pay();

        $this->write('Running user cancelled: 0773333333', 'info');
        $this->phone = '0773333333';
        $this->pay();

        $this->write('Running insufficient balance: 0774444444', 'info');
        $this->phone = '0774444444';
        $this->pay();
    }

    $this->pollUrl = null;
    $this->save('Live - ');
};
$newSession = function () {
    $this->redirect('?id=' . \Illuminate\Support\Str::random());
};

mount(function () use ($cache) {
    if (!request('id')) {
        $this->redirect('?id=' . session('activeSessionId', \Illuminate\Support\Str::random()));
        return;
    }

    $restore = $cache->get($this->sessionId) ?? [];

    if (empty($restore)) {
        $this->write('Welcome, ' . auth()->user()->email);
    } else {
        $this->fill($restore);
    }
});
?>


<div class="flex h-[90vh] w-full flex-1 flex-col gap-4 rounded-xl">
    <div class="grid auto-rows-min gap-4 md:grid-cols-4">
        <div
            class="relative p-4 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <flux:input label="{{ __('Session Name') }}" wire:model="name"/>
        </div>
        <div
            class="relative p-4 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <flux:input label="{{ __('Integration ID') }}" wire:model="integrationId" placeholder="12345..."/>
        </div>
        <div
            class="relative p-4 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <flux:input label="{{ __('Integration Key') }}" wire:model="integrationKey" placeholder="a1b2c3-d4e5f6..."/>
        </div>
        <div
            class="relative p-4 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <flux:input label="{{ __('Integration Email') }}" wire:model="integrationEmail"
                        placeholder="user@example.com..."/>
        </div>
    </div>

    <div
        class="relative h-full flex-1 flex flex-col overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <pre
            class="p-4 border-b border-neutral-200 dark:border-neutral-700"><code>composer require paynow/php-sdk</code></pre>
        <div class="grow overflow-auto">
            @forelse(collect($log)->reverse() as $item)
                <div class="mb-3 p-4">
                    <small class="opacity-40 block">{{ $item['time'] }}</small>
                    <span class="{{ $item['class'] }}">{{ $item['log'] }}</span>
                </div>
            @empty
                <div class="h-full flex items-center justify-center flex-col opacity-50">
                    <flux:icon.adjustments-horizontal class="w-44 h-44"/>
                    {{ __('Empty log') }}
                </div>
            @endforelse
        </div>
        <div class="absolute top-3 right-4">
            <flux:button icon-trailing="x-mark" size="sm" wire:click="clear">
                {{ __('Clear log') }}
            </flux:button>
        </div>
        @if($pollUrl)
            <div class="flex items-center bg-green-500/20 space-x-4 p-4 border-t border-neutral-200 dark:border-neutral-700">
                <div class="grow flex space-x-4">
                    <flux:icon.currency-dollar />
                    <span>{{ $pollUrl }}</span>
                </div>
                <flux:button size="sm" wire:click="$set('pollUrl', null)">
                    <flux:icon.x-mark/>
                </flux:button>
            </div>
        @endif
        <div class="flex space-x-4 p-4 border-t border-neutral-200 dark:border-neutral-700">
            <flux:select wire:model="method" label="{{ __('Method') }}">
                <flux:select.option>Paynow (Web Redirect)</flux:select.option>
                <flux:select.option>EcoCash</flux:select.option>
                <flux:select.option>OneMoney</flux:select.option>
                <flux:select.option>TeleCash</flux:select.option>
                <flux:select.option>InnBucks</flux:select.option>
            </flux:select>
            <flux:input list="phones" wire:model="phone" type="tel" label="{{ __('Phone') }}" placeholder="Enter phone..."/>
            <flux:input wire:model="amount" type="number" label="{{ __('Amount ($)') }}" placeholder="Enter amount..."/>
        </div>
        <div wire:poll.visible.5s class="p-4 border-t border-neutral-200 dark:border-neutral-700">
            <flux:button wire:click="pay">Submit Payment</flux:button>
            @if($pollUrl)
                <flux:button wire:click="check">Check Payment ({{ $this->paid ? 'Paid' : 'Not Paid' }})</flux:button>
            @endif
            <flux:button wire:click="save">Save Session</flux:button>
            <flux:button wire:click="live">Go Live</flux:button>
            <flux:button wire:click="newSession">New Session</flux:button>
        </div>
    </div>

    <datalist id="phones">
        <option>0771111111</option>
        <option>0772222222</option>
        <option>0773333333</option>
        <option>0774444444</option>
    </datalist>
</div>


