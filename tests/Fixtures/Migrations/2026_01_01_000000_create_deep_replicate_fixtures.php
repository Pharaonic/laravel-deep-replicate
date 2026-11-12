<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateDeepReplicateFixtures extends Migration
{
    public function up(): void
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        Schema::create('customers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('sku')->unique();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('assigned_customer_id')->nullable();
            $table->string('number');
            $table->string('status')->default('open');
            $table->string('slug')->nullable()->unique();
            $table->string('external_ref')->nullable();
            $table->text('meta')->nullable();
            $table->text('secret')->nullable();
            $table->decimal('total', 10, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('customer_id')->references('id')->on('customers');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('title');
            $table->string('external_id')->nullable();
            $table->string('state')->default('new');
            $table->boolean('active')->default(true);
            $table->string('sku')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('order_id')->references('id')->on('orders');
            $table->foreign('product_id')->references('id')->on('products');
        });

        Schema::create('item_options', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_item_id');
            $table->string('name');
            $table->timestamps();

            $table->foreign('order_item_id')->references('id')->on('order_items');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->string('code');
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('order_item_tag', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('tag_id');
            $table->boolean('primary')->default(false);
            $table->integer('weight')->default(0);
            $table->text('options')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->foreign('order_item_id')->references('id')->on('order_items');
            $table->foreign('tag_id')->references('id')->on('tags');
        });

        Schema::create('order_tag_unique', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('tag_id');
            $table->string('code')->nullable()->unique();
            $table->unique(['order_id', 'tag_id']);

            $table->foreign('order_id')->references('id')->on('orders');
            $table->foreign('tag_id')->references('id')->on('tags');
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
        });

        Schema::create('teams', function (Blueprint $table) use ($sqlite) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->timestamps();

            if ($sqlite) {
                // SQLite accepts a reference to a table created later.
                $table->foreign('owner_id')->references('id')->on('users');
            }
        });

        Schema::create('users', function (Blueprint $table) use ($sqlite) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('current_team_id')->nullable();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestamps();

            $table->foreign('country_id')->references('id')->on('countries');

            if ($sqlite) {
                $table->foreign('current_team_id')->references('id')->on('teams');
            }
        });

        Schema::table('teams', function (Blueprint $table) use ($sqlite) {
            if (! $sqlite) {
                $table->foreign('owner_id')->references('id')->on('users');
            }
        });

        if (! $sqlite) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('current_team_id')->references('id')->on('teams');
            });
        }

        // NOT NULL in both directions: a cycle the database cannot satisfy.
        Schema::create('strict_users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('strict_team_id');
            $table->string('name');
        });

        Schema::create('strict_teams', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('title');
            $table->timestamps();

            $table->foreign('author_id')->references('id')->on('users');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('post_id');
            $table->string('body');
            $table->boolean('approved')->default(false);
            $table->timestamps();

            $table->foreign('post_id')->references('id')->on('posts');
        });

        Schema::create('videos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('images', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('imageable_id')->nullable();
            $table->string('imageable_type')->nullable();
            $table->string('url');
            $table->boolean('featured')->default(false);
            $table->timestamps();
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tag_id');
            $table->unsignedBigInteger('taggable_id');
            $table->string('taggable_type');
            $table->string('context')->nullable();

            $table->foreign('tag_id')->references('id')->on('tags');
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('description');
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->string('uuid')->primary();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->string('uuid')->primary();
            $table->string('document_uuid');
            $table->integer('number');
            $table->timestamps();

            $table->foreign('document_uuid')->references('uuid')->on('documents');
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('reference')->unique();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('subject');
            $table->timestamps();
        });

        Schema::create('legacy_shelves', function (Blueprint $table) {
            $table->increments('shelf_no');
            $table->string('code')->unique();
            $table->string('label');
        });

        Schema::create('legacy_books', function (Blueprint $table) {
            $table->increments('book_no');
            $table->string('shelf_code');
            $table->string('isbn')->unique();
            $table->string('title');
            $table->timestamp('added_on')->nullable();
            $table->timestamp('changed_on')->nullable();

            $table->foreign('shelf_code')->references('code')->on('legacy_shelves');
        });

        Schema::create('legacy_authors', function (Blueprint $table) {
            $table->increments('author_no');
            $table->string('pen_name')->unique();
        });

        Schema::create('legacy_book_author', function (Blueprint $table) {
            $table->string('book_ref');
            $table->string('author_ref');
            $table->string('role')->nullable();

            $table->foreign('book_ref')->references('isbn')->on('legacy_books');
            $table->foreign('author_ref')->references('pen_name')->on('legacy_authors');
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::disableForeignKeyConstraints();

        foreach ([
            'legacy_book_author', 'legacy_authors', 'legacy_books', 'legacy_shelves', 'tickets', 'pages', 'documents',
            'activities', 'taggables', 'images', 'videos', 'comments', 'posts', 'strict_teams', 'strict_users',
            'users', 'teams', 'countries', 'order_tag_unique', 'order_item_tag', 'tags', 'invoices', 'payments',
            'item_options', 'order_items', 'orders', 'products', 'customers',
        ] as $table) {
            if ($driver === 'pgsql') {
                // users and teams reference each other.
                DB::statement('drop table if exists "'.$table.'" cascade');
            } else {
                Schema::dropIfExists($table);
            }
        }

        Schema::enableForeignKeyConstraints();
    }
}
