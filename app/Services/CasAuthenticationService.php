<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class CasAuthenticationService
{
    public function serviceUrl(): string
    {
        return rtrim((string) (config('services.cas.service_url') ?: route('cas.callback')), '/');
    }

    public function loginUrl(string $returnUrl = '/dashboard'): string
    {
        session(['cas.return_url' => $this->safeReturnUrl($returnUrl)]);

        return $this->url('login_path').'?service='.rawurlencode($this->serviceUrl());
    }

    public function logoutUrl(): string
    {
        return $this->url('logout_path').'?service='.rawurlencode(url('/'));
    }

    /** @return array{account:string,user:string,attributes:array<string,string>} */
    public function validateTicket(string $ticket): array
    {
        $options = ['query' => ['service' => $this->serviceUrl(), 'ticket' => $ticket]];
        if ($ca = config('services.cas.ca_bundle')) {
            $options['verify'] = $ca;
        }
        $response = Http::timeout(config('services.cas.timeout'))->withOptions($options)->get($this->url('validate_path'));
        abort_unless($response->successful(), 503, '统一认证服务暂不可用。');
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $loaded = $dom->loadXML($response->body(), LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            throw ValidationException::withMessages(['ticket' => '统一认证返回内容无法解析。']);
        }
        $success = $dom->getElementsByTagNameNS('http://www.yale.edu/tp/cas', 'authenticationSuccess')->item(0);
        if (! $success) {
            throw ValidationException::withMessages(['ticket' => '登录票据无效或已过期。']);
        }
        $user = trim((string) $dom->getElementsByTagNameNS('http://www.yale.edu/tp/cas', 'user')->item(0)?->nodeValue);
        $attributes = [];
        $attributeRoot = $dom->getElementsByTagNameNS('http://www.yale.edu/tp/cas', 'attributes')->item(0);
        if ($attributeRoot) {
            foreach ($attributeRoot->childNodes as $node) {
                if ($node instanceof \DOMElement) {
                    $attributes[$node->localName] = trim((string) $node->nodeValue);
                }
            }
        }
        $key = config('services.cas.account_attribute');
        $account = $key === 'user' ? $user : ($attributes[$key] ?? '');
        if ($account === '') {
            throw ValidationException::withMessages(['ticket' => '统一认证未返回可匹配的账号。']);
        }

        return ['account' => $account, 'user' => $user, 'attributes' => $attributes];
    }

    private function url(string $path): string
    {
        return rtrim(config('services.cas.base_url'), '/').'/'.ltrim(config("services.cas.$path"), '/');
    }

    private function safeReturnUrl(string $url): string
    {
        return str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $url : '/dashboard';
    }
}
