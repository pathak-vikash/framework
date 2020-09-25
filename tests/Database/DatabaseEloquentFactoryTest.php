<?php

namespace Illuminate\Tests\Database;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Eloquent\Model as Eloquent;
use PHPUnit\Framework\TestCase;

class DatabaseEloquentFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        Container::getInstance()->singleton(\Faker\Generator::class, function ($app, $parameters) {
            return \Faker\Factory::create('en_US');
        });

        $db = new DB;

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $db->bootEloquent();
        $db->setAsGlobal();

        $this->createSchema();
    }

    /**
     * Setup the database schema.
     *
     * @return void
     */
    public function createSchema()
    {
        $this->schema()->create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('options')->nullable();
            $table->timestamps();
        });

        $this->schema()->create('posts', function ($table) {
            $table->increments('id');
            $table->foreignId('user_id');
            $table->string('title');
            $table->timestamps();
        });

        $this->schema()->create('comments', function ($table) {
            $table->increments('id');
            $table->foreignId('commentable_id');
            $table->string('commentable_type');
            $table->string('body');
            $table->timestamps();
        });

        $this->schema()->create('roles', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        $this->schema()->create('role_user', function ($table) {
            $table->foreignId('role_id');
            $table->foreignId('user_id');
            $table->string('admin')->default('N');
        });
    }

    /**
     * Tear down the database schema.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->schema()->drop('users');
    }

    public function test_basic_model_can_be_created()
    {
        $user = FactoryTestUserFactory::new()->create();
        $this->assertInstanceOf(Eloquent::class, $user);

        $user = FactoryTestUserFactory::new()->createOne();
        $this->assertInstanceOf(Eloquent::class, $user);

        $user = FactoryTestUserFactory::new()->create(['name' => 'Taylor Otwell']);
        $this->assertInstanceOf(Eloquent::class, $user);
        $this->assertEquals('Taylor Otwell', $user->name);

        $users = FactoryTestUserFactory::new()->createMany([
            ['name' => 'Taylor Otwell'],
            ['name' => 'Jeffrey Way'],
        ]);
        $this->assertInstanceOf(Collection::class, $users);
        $this->assertCount(2, $users);

        $users = FactoryTestUserFactory::times(10)->create();
        $this->assertCount(10, $users);
    }

    public function test_expanded_closure_attributes_are_resolved_and_passed_to_closures()
    {
        $user = FactoryTestUserFactory::new()->create([
            'name' => function () {
                return 'taylor';
            },
            'options' => function ($attributes) {
                return $attributes['name'].'-options';
            },
        ]);

        $this->assertEquals('taylor-options', $user->options);
    }

    public function test_make_creates_unpersisted_model_instance()
    {
        $user = FactoryTestUserFactory::new()->makeOne();
        $this->assertInstanceOf(Eloquent::class, $user);

        $user = FactoryTestUserFactory::new()->make(['name' => 'Taylor Otwell']);

        $this->assertInstanceOf(Eloquent::class, $user);
        $this->assertEquals('Taylor Otwell', $user->name);
        $this->assertCount(0, FactoryTestUser::all());
    }

    public function test_basic_model_attributes_can_be_created()
    {
        $user = FactoryTestUserFactory::new()->raw();
        $this->assertIsArray($user);

        $user = FactoryTestUserFactory::new()->raw(['name' => 'Taylor Otwell']);
        $this->assertIsArray($user);
        $this->assertEquals('Taylor Otwell', $user['name']);
    }

    public function test_expanded_model_attributes_can_be_created()
    {
        $post = FactoryTestPostFactory::new()->raw();
        $this->assertIsArray($post);

        $post = FactoryTestPostFactory::new()->raw(['title' => 'Test Title']);
        $this->assertIsArray($post);
        $this->assertIsInt($post['user_id']);
        $this->assertEquals('Test Title', $post['title']);
    }

    public function test_after_creating_and_making_callbacks_are_called()
    {
        $user = FactoryTestUserFactory::new()
                        ->afterMaking(function ($user) {
                            $_SERVER['__test.user.making'] = $user;
                        })
                        ->afterCreating(function ($user) {
                            $_SERVER['__test.user.creating'] = $user;
                        })
                        ->create();

        $this->assertSame($user, $_SERVER['__test.user.making']);
        $this->assertSame($user, $_SERVER['__test.user.creating']);

        unset($_SERVER['__test.user.making']);
        unset($_SERVER['__test.user.creating']);
    }

    public function test_has_many_relationship()
    {
        $users = FactoryTestUserFactory::times(10)
                        ->has(
                            FactoryTestPostFactory::times(3)
                                    ->state(function ($attributes, $user) {
                                        // Test parent is passed to child state mutations...
                                        $_SERVER['__test.post.state-user'] = $user;

                                        return [];
                                    })
                                    // Test parents passed to callback...
                                    ->afterCreating(function ($post, $user) {
                                        $_SERVER['__test.post.creating-post'] = $post;
                                        $_SERVER['__test.post.creating-user'] = $user;
                                    }),
                            'posts'
                        )
                        ->create();

        $this->assertCount(10, FactoryTestUser::all());
        $this->assertCount(30, FactoryTestPost::all());
        $this->assertCount(3, FactoryTestUser::latest()->first()->posts);

        $this->assertInstanceOf(Eloquent::class, $_SERVER['__test.post.creating-post']);
        $this->assertInstanceOf(Eloquent::class, $_SERVER['__test.post.creating-user']);
        $this->assertInstanceOf(Eloquent::class, $_SERVER['__test.post.state-user']);

        unset($_SERVER['__test.post.creating-post']);
        unset($_SERVER['__test.post.creating-user']);
        unset($_SERVER['__test.post.state-user']);
    }

    public function test_belongs_to_relationship()
    {
        $posts = FactoryTestPostFactory::times(3)
                        ->for(FactoryTestUserFactory::new(['name' => 'Taylor Otwell']), 'user')
                        ->create();

        $this->assertCount(3, $posts->filter(function ($post) {
            return $post->user->name == 'Taylor Otwell';
        }));

        $this->assertCount(1, FactoryTestUser::all());
        $this->assertCount(3, FactoryTestPost::all());
    }

    public function test_morph_to_relationship()
    {
        $posts = FactoryTestCommentFactory::times(3)
                        ->for(FactoryTestPostFactory::new(['title' => 'Test Title']), 'commentable')
                        ->create();

        $this->assertEquals('Test Title', FactoryTestPost::first()->title);
        $this->assertCount(3, FactoryTestPost::first()->comments);

        $this->assertCount(1, FactoryTestPost::all());
        $this->assertCount(3, FactoryTestComment::all());
    }

    public function test_belongs_to_many_relationship()
    {
        $users = FactoryTestUserFactory::times(3)
                        ->hasAttached(
                            FactoryTestRoleFactory::times(3)->afterCreating(function ($role, $user) {
                                $_SERVER['__test.role.creating-role'] = $role;
                                $_SERVER['__test.role.creating-user'] = $user;
                            }),
                            ['admin' => 'Y'],
                            'roles'
                        )
                        ->create();

        $this->assertCount(9, FactoryTestRole::all());

        $user = FactoryTestUser::latest()->first();

        $this->assertCount(3, $user->roles);
        $this->assertEquals('Y', $user->roles->first()->pivot->admin);

        $this->assertInstanceOf(Eloquent::class, $_SERVER['__test.role.creating-role']);
        $this->assertInstanceOf(Eloquent::class, $_SERVER['__test.role.creating-user']);

        unset($_SERVER['__test.role.creating-role']);
        unset($_SERVER['__test.role.creating-user']);
    }

    public function test_sequences()
    {
        $users = FactoryTestUserFactory::times(2)->sequence(
            ['name' => 'Taylor Otwell'],
            ['name' => 'Abigail Otwell'],
        )->create();

        $this->assertEquals('Taylor Otwell', $users[0]->name);
        $this->assertEquals('Abigail Otwell', $users[1]->name);

        $user = FactoryTestUserFactory::new()
                        ->hasAttached(
                            FactoryTestRoleFactory::times(4),
                            new Sequence(['admin' => 'Y'], ['admin' => 'N']),
                            'roles'
                        )
                        ->create();

        $this->assertCount(4, $user->roles);

        $this->assertCount(2, $user->roles->filter(function ($role) {
            return $role->pivot->admin == 'Y';
        }));

        $this->assertCount(2, $user->roles->filter(function ($role) {
            return $role->pivot->admin == 'N';
        }));
    }

    public function test_resolve_nested_model_factories()
    {
        Factory::useNamespace('Factories\\');

        $resolves = [
            'App\\Foo' => 'Factories\\FooFactory',
            'App\\Models\\Foo' => 'Factories\\FooFactory',
            'App\\Models\\Nested\\Foo' => 'Factories\\Nested\\FooFactory',
            'App\\Models\\Really\\Nested\\Foo' => 'Factories\\Really\\Nested\\FooFactory',
        ];

        foreach ($resolves as $model => $factory) {
            $this->assertEquals($factory, Factory::resolveFactoryName($model));
        }
    }

    public function test_model_has_factory()
    {
        Factory::guessFactoryNamesUsing(function ($model) {
            return $model.'Factory';
        });

        $this->assertInstanceOf(FactoryTestUserFactory::class, FactoryTestUser::factory());
    }

    public function test_dynamic_has_and_for_methods()
    {
        Factory::guessFactoryNamesUsing(function ($model) {
            return $model.'Factory';
        });

        $user = FactoryTestUserFactory::new()->hasPosts(3)->create();

        $this->assertCount(3, $user->posts);

        $post = FactoryTestPostFactory::new()
                            ->forAuthor(['name' => 'Taylor Otwell'])
                            ->hasComments(2)
                            ->create();

        $this->assertInstanceOf(FactoryTestUser::class, $post->author);
        $this->assertEquals('Taylor Otwell', $post->author->name);
        $this->assertCount(2, $post->comments);
    }

    /**
     * Get a database connection instance.
     *
     * @return \Illuminate\Database\ConnectionInterface
     */
    protected function connection()
    {
        return Eloquent::getConnectionResolver()->connection();
    }

    /**
     * Get a schema builder instance.
     *
     * @return \Illuminate\Database\Schema\Builder
     */
    protected function schema()
    {
        return $this->connection()->getSchemaBuilder();
    }
}

class FactoryTestUserFactory extends Factory
{
    protected $model = FactoryTestUser::class;

    public function definition()
    {
        return [
            'name' => $this->faker->name,
            'options' => null,
        ];
    }
}

class FactoryTestUser extends Eloquent
{
    use HasFactory;

    protected $table = 'users';

    public function posts()
    {
        return $this->hasMany(FactoryTestPost::class, 'user_id');
    }

    public function roles()
    {
        return $this->belongsToMany(FactoryTestRole::class, 'role_user', 'user_id', 'role_id')->withPivot('admin');
    }
}

class FactoryTestPostFactory extends Factory
{
    protected $model = FactoryTestPost::class;

    public function definition()
    {
        return [
            'user_id' => FactoryTestUserFactory::new(),
            'title' => $this->faker->name,
        ];
    }
}

class FactoryTestPost extends Eloquent
{
    protected $table = 'posts';

    public function user()
    {
        return $this->belongsTo(FactoryTestUser::class, 'user_id');
    }

    public function author()
    {
        return $this->belongsTo(FactoryTestUser::class, 'user_id');
    }

    public function comments()
    {
        return $this->morphMany(FactoryTestComment::class, 'commentable');
    }
}

class FactoryTestCommentFactory extends Factory
{
    protected $model = FactoryTestComment::class;

    public function definition()
    {
        return [
            'commentable_id' => FactoryTestPostFactory::new(),
            'commentable_type' => FactoryTestPost::class,
            'body' => $this->faker->name,
        ];
    }
}

class FactoryTestComment extends Eloquent
{
    protected $table = 'comments';

    public function commentable()
    {
        return $this->morphTo();
    }
}

class FactoryTestRoleFactory extends Factory
{
    protected $model = FactoryTestRole::class;

    public function definition()
    {
        return [
            'name' => $this->faker->name,
        ];
    }
}

class FactoryTestRole extends Eloquent
{
    protected $table = 'roles';

    public function users()
    {
        return $this->belongsToMany(FactoryTestUser::class, 'role_user', 'role_id', 'user_id')->withPivot('admin');
    }
}
