<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Bridge;

/**
 * The mobile native bridge.
 *
 * Unlike desktop — where PHP and the runtime are separate processes talking JSON
 * over loopback HTTP — mobile compiles PHP into the app and exposes a single
 * extension function:
 *
 *     nativephp_call(string $method, string $jsonPayload): ?string
 *
 * That is the entire transport: a dotted method name and a JSON string, with an
 * optional JSON string back. It is completely framework-agnostic, which is why
 * mobile needs no protocol work at all — only wrappers and a SAPI shim.
 *
 * Results arrive one of two ways depending on the method: synchronously as the
 * return value (SecureStorage.Get, Network.Status, Device.GetInfo), or later as an
 * event pushed into the app (Camera.GetPhoto, Microphone.GetRecording) because the
 * user has to act first.
 */
interface BridgeInterface
{
    /** False when running outside a NativePHP mobile app — the extension is absent. */
    public function isAvailable(): bool;

    /**
     * Call a native method and decode its JSON reply.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null Null when the method returns nothing, the
     *                                   bridge is unavailable, or the reply was not
     *                                   JSON
     */
    public function call(string $method, array $payload = []): ?array;

    /**
     * Call a native method whose result arrives later as an event.
     *
     * Returns whether the call was handed to the native layer — not whether the
     * user did anything. Treating a `true` here as "the photo was taken" is the
     * mistake this separate method exists to prevent.
     *
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $method, array $payload = []): bool;

    /**
     * The raw reply, undecoded. For methods returning a bare string or base64
     * blob rather than a JSON object.
     *
     * @param array<string, mixed> $payload
     */
    public function raw(string $method, array $payload = []): ?string;
}
