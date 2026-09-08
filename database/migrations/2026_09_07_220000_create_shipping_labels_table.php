<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('easypost_shipment_id')->unique();
            $table->string('tracking_code')->nullable()->index();
            $table->string('carrier');
            $table->string('service');
            $table->decimal('rate', 8, 2);
            $table->char('currency', 3);
            $table->json('from_address');
            $table->json('to_address');
            $table->json('parcel');
            $table->string('label_file_path');
            $table->string('label_file_type');
            $table->string('label_url');
            $table->json('easypost_response');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_labels');
    }
};
