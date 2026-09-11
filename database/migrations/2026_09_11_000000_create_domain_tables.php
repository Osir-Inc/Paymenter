<?php

use App\Models\Order;
use App\Models\Registrar;
use App\Models\Tld;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tlds', function (Blueprint $table) {
            $table->id();
            $table->string('tld')->unique();
            $table->foreignIdFor(Registrar::class, 'registrar_id')->nullable()->constrained('extensions')->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->boolean('featured')->default(false);
            $table->boolean('supports_transfer')->default(true);
            $table->unsignedTinyInteger('min_years')->default(1);
            $table->unsignedTinyInteger('max_years')->default(10);
            $table->integer('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('tld_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Tld::class)->constrained()->cascadeOnDelete();
            $table->char('currency_code', 3);
            $table->unsignedTinyInteger('years');
            $table->decimal('register', 17, 2)->nullable();
            $table->decimal('renew', 17, 2)->nullable();
            $table->decimal('transfer', 17, 2)->nullable();
            $table->unique(['tld_id', 'currency_code', 'years']);
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained();
            $table->foreignIdFor(Order::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(Tld::class)->constrained();
            $table->foreignIdFor(Registrar::class, 'registrar_id')->constrained('extensions');
            $table->string('name');
            $table->string('domain')->index();
            $table->string('status')->default('pending');
            $table->string('action')->default('register');
            $table->unsignedTinyInteger('years')->default(1);
            $table->decimal('price', 17, 2)->default(0);
            $table->char('currency_code', 3);
            $table->text('auth_code')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->boolean('privacy')->default(false);
            $table->json('nameservers')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignIdFor(Tld::class)->nullable()->after('plan_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->nullable()->after('tld_id');
            $table->string('domain_action')->nullable()->after('domain');
            $table->unsignedTinyInteger('years')->nullable()->after('domain_action');
            $table->text('auth_code')->nullable()->after('years');
            $table->decimal('domain_price', 17, 2)->nullable()->after('auth_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(Tld::class);
            $table->dropColumn(['domain', 'domain_action', 'years', 'auth_code', 'domain_price']);
        });
        Schema::dropIfExists('domains');
        Schema::dropIfExists('tld_prices');
        Schema::dropIfExists('tlds');
    }
};
