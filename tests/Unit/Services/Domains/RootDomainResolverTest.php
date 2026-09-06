<?php

namespace Tests\Unit\Services\Domains;

use App\Services\Domains\RootDomainResolver;
use Tests\TestCase;

class RootDomainResolverTest extends TestCase
{
    private RootDomainResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new RootDomainResolver;
    }

    public function test_resolves_standard_tld(): void
    {
        $this->assertSame('example.com', $this->resolver->resolve('example.com'));
        $this->assertSame('example.com', $this->resolver->resolve('www.example.com'));
        $this->assertSame('example.com', $this->resolver->resolve('sub.staging.example.com'));
    }

    public function test_resolves_multi_part_tld(): void
    {
        $this->assertSame('example.co.uk', $this->resolver->resolve('example.co.uk'));
        $this->assertSame('example.co.uk', $this->resolver->resolve('sub.example.co.uk'));
        $this->assertSame('example.com.au', $this->resolver->resolve('test.example.com.au'));
    }

    public function test_handles_urls_with_schemes_and_paths(): void
    {
        $this->assertSame('example.com', $this->resolver->resolve('https://example.com/some/path'));
        $this->assertSame('example.co.uk', $this->resolver->resolve('http://sub.example.co.uk:8080/'));
    }

    public function test_handles_invalid_or_empty_inputs(): void
    {
        $this->assertSame('', $this->resolver->resolve(''));
        $this->assertSame('localhost', $this->resolver->resolve('localhost'));
    }

    public function test_strips_queries_and_fragments(): void
    {
        $this->assertSame('example.com', $this->resolver->resolve('https://example.com?query=1&foo=bar'));
        $this->assertSame('example.com', $this->resolver->resolve('example.com#section'));
    }
}
