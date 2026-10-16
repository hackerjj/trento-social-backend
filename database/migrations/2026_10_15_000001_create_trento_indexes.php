<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $db = DB::connection('mongodb')->getMongoDB();

        // Enlaces: code unico (garantia dura contra colisiones del generador).
        $db->selectCollection('trento_links')->createIndex(['code' => 1], ['unique' => true]);

        // Clics: compuesto code + ts para stats por enlace y serie de tiempo.
        $db->selectCollection('trento_clicks')->createIndex(['code' => 1, 'ts' => -1]);

        // Registros: created_at desc para listar y exportar por fecha.
        $db->selectCollection('trento_registrations')->createIndex(['created_at' => -1]);
    }

    public function down(): void
    {
        $db = DB::connection('mongodb')->getMongoDB();

        $db->selectCollection('trento_links')->dropIndex('code_1');
        $db->selectCollection('trento_clicks')->dropIndex('code_1_ts_-1');
        $db->selectCollection('trento_registrations')->dropIndex('created_at_-1');
    }
};
