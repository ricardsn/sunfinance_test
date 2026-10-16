<?php

declare(strict_types=1);

namespace App\Tests\Unit\Factory;

use App\Exception\ValidationFailedException;
use App\Factory\PaginationFactory;
use App\Model\Pagination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaginationFactoryTest extends TestCase
{
    private PaginationFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new PaginationFactory();
    }

    public function testAnAbsentLimitAndOffsetFallBackToTheDefaults(): void
    {
        $pagination = $this->factory->create(null, null);

        self::assertSame(Pagination::DEFAULT_LIMIT, $pagination->limit);
        self::assertSame(0, $pagination->offset);
    }

    public function testTheQueryStringValuesAreRead(): void
    {
        $pagination = $this->factory->create('25', '50');

        self::assertSame(25, $pagination->limit);
        self::assertSame(50, $pagination->offset);
    }

    public function testTheCapItselfIsAllowed(): void
    {
        self::assertSame(Pagination::MAX_LIMIT, $this->factory->create((string) Pagination::MAX_LIMIT, null)->limit);
    }

    /** @param list<string> $expectedFields */
    #[DataProvider('invalidValues')]
    public function testAnInvalidValueIsRejected(mixed $limit, mixed $offset, array $expectedFields): void
    {
        try {
            $this->factory->create($limit, $offset);
            self::fail('Expected the pagination to be rejected.');
        } catch (ValidationFailedException $e) {
            self::assertSame($expectedFields, array_keys($e->getErrors()));
        }
    }

    /** @return array<string, array{mixed, mixed, list<string>}> */
    public static function invalidValues(): array
    {
        return [
            'limit zero' => ['0', null, ['limit']],
            'limit over the cap' => [(string) (Pagination::MAX_LIMIT + 1), null, ['limit']],
            'limit negative' => ['-1', null, ['limit']],
            'limit not a number' => ['abc', null, ['limit']],
            'limit with decimals' => ['1.5', null, ['limit']],
            'limit repeated in the query string' => [['1', '2'], null, ['limit']],
            'offset negative' => [null, '-1', ['offset']],
            'offset not a number' => [null, 'abc', ['offset']],
            'both invalid' => ['abc', 'abc', ['limit', 'offset']],
        ];
    }
}
