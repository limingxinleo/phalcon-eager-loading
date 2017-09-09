# phalcon-eager-loading
Phalcon Eager Loading

## 安装
突然发现 phalcon/incubator 中已经有了eager load
~~~
composer require phalcon/incubator

use Phalcon\Mvc\Model\EagerLoadingTrait

~~~

## 使用
```php
<?php
use Phalcon\Mvc\Model\EagerLoading\Loader,
	Phalcon\Mvc\Model\EagerLoading\QueryBuilder;

$robotsAndParts = Robot::with('Parts');

// Equivalent to:

$robots = Robot::find();
foreach ($robots as $robot) {
	$robot->parts; // $robot->__get('parts')
}

// Or

$robot = Robot::findFirst()->load('Parts');

// Equivalent to:

$robot = Robot::findFirst();
$robots->parts; // $robot->__get('parts')

// Because Robot::find() returns a resultset, so in that case this is solved with:
$robots = Loader::fromResultset(Robot::find(), 'Parts'); # Equivalent to the second example

// Multiple and nested relations can be used too
$robots = Robot::with('Parts', 'Foo.Bar');

// And arguments can be passed to the find method
$robots = Robot::with('Parts', 'Foo.Bar', ['limit' => 5]);

// And constraints
$robots = Robot::with(
	[
		'Parts',
		'Foo.Bar' => function (QueryBuilder $builder) {
			// Limit Bar
			$builder->limit(5);
		}
	],
	[
		'limit' => 5
	]
);

```