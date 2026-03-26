<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transcription_texts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transcription_id')->unique()->constrained('transcriptions')->onDelete('cascade');
            $table->longText('transcript_text');
            $table->json('segments')->nullable();
            $table->json('words')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        if (Schema::hasTable('transcriptions') && Schema::hasColumn('transcriptions', 'transcript_text')) {
            DB::table('transcriptions')
                ->select('id', 'transcript_text', 'segments', 'words', 'created_at', 'updated_at')
                ->orderBy('id')
                ->chunkById(200, function ($rows) {
                    $insert = [];
                    foreach ($rows as $row) {
                        $insert[] = [
                            'transcription_id' => $row->id,
                            'transcript_text' => $row->transcript_text,
                            'segments' => $row->segments,
                            'words' => $row->words,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ];
                    }
                    if (!empty($insert)) {
                        DB::table('transcription_texts')->insert($insert);
                    }
                });

            Schema::table('transcriptions', function (Blueprint $table) {
                if (Schema::hasColumn('transcriptions', 'transcript_text')) {
                    $table->dropColumn('transcript_text');
                }
                if (Schema::hasColumn('transcriptions', 'segments')) {
                    $table->dropColumn('segments');
                }
                if (Schema::hasColumn('transcriptions', 'words')) {
                    $table->dropColumn('words');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('transcriptions')) {
            Schema::dropIfExists('transcription_texts');
            return;
        }

        Schema::table('transcriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('transcriptions', 'transcript_text')) {
                $table->longText('transcript_text')->nullable();
            }
            if (!Schema::hasColumn('transcriptions', 'segments')) {
                $table->json('segments')->nullable();
            }
            if (!Schema::hasColumn('transcriptions', 'words')) {
                $table->json('words')->nullable();
            }
        });

        if (Schema::hasTable('transcription_texts')) {
            DB::table('transcription_texts')
                ->select('transcription_id', 'transcript_text', 'segments', 'words')
                ->orderBy('id')
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $row) {
                        DB::table('transcriptions')
                            ->where('id', $row->transcription_id)
                            ->update([
                                'transcript_text' => $row->transcript_text,
                                'segments' => $row->segments,
                                'words' => $row->words,
                            ]);
                    }
                });
        }

        Schema::dropIfExists('transcription_texts');
    }
};
