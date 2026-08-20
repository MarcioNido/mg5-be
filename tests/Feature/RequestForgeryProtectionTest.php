<?php

namespace Tests\Feature;

use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

class RequestForgeryProtectionTest extends TestCase
{
    public function test_same_origin_post_is_accepted_without_a_csrf_token(): void
    {
        $request = $this->requestWithSession('same-origin');

        $response = $this->middleware()->handle(
            $request,
            static fn () => response('accepted')
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('accepted', $response->getContent());
    }

    public function test_cross_site_post_without_a_csrf_token_is_rejected(): void
    {
        $this->expectException(TokenMismatchException::class);

        $this->middleware()->handle(
            $this->requestWithSession('cross-site'),
            static fn () => response('accepted')
        );
    }

    private function middleware(): PreventRequestForgery
    {
        $encrypter = new Encrypter(str_repeat('a', 32), 'AES-256-CBC');

        return new class(app(), $encrypter) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };
    }

    private function requestWithSession(string $secFetchSite): Request
    {
        $request = Request::create('/', 'POST', server: [
            'HTTP_SEC_FETCH_SITE' => $secFetchSite,
        ]);
        $request->setLaravelSession(app('session')->driver());

        return $request;
    }
}
