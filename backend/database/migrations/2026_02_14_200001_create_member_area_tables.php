<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Área de Membros (configuração do produtor)
        if (!Schema::hasTable('member_areas')) {
        Schema::create('member_areas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            
            // Branding
            $table->string('logo_url')->nullable();
            $table->string('favicon_url')->nullable();
            $table->string('primary_color', 7)->default('#8B5CF6');
            $table->string('secondary_color', 7)->default('#EC4899');
            $table->string('accent_color', 7)->default('#06B6D4');
            $table->string('background_color', 7)->default('#0F0F0F');
            $table->string('text_color', 7)->default('#FFFFFF');
            $table->string('font_family')->default('Inter');
            $table->enum('theme_mode', ['dark', 'light'])->default('dark');
            
            // Layout
            $table->enum('layout_style', ['netflix', 'traditional', 'minimal', 'grid', 'list'])->default('netflix');
            $table->string('banner_url')->nullable();
            $table->text('welcome_message')->nullable();
            $table->string('welcome_video_url')->nullable();
            
            // Domínio
            $table->string('subdomain')->unique()->nullable();
            $table->string('custom_domain')->unique()->nullable();
            $table->boolean('domain_verified')->default(false);
            $table->boolean('ssl_enabled')->default(false);
            
            // Configurações
            $table->jsonb('featured_courses')->default('[]');
            $table->jsonb('home_sections')->default('[]');
            $table->jsonb('custom_css')->nullable();
            $table->jsonb('settings')->default('{}');
            
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->index('subdomain');
            $table->index('custom_domain');
            $table->index('is_active');
        });
        }

        // Cursos
        if (!Schema::hasTable('courses')) {
        Schema::create('courses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('product_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->text('short_description')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('cover_url')->nullable();
            $table->string('trailer_url')->nullable();
            $table->string('instructor_name')->nullable();
            $table->string('instructor_avatar')->nullable();
            $table->text('instructor_bio')->nullable();
            $table->integer('duration_minutes')->default(0);
            $table->enum('level', ['beginner', 'intermediate', 'advanced', 'all'])->default('all');
            $table->jsonb('tags')->default('[]');
            $table->jsonb('requirements')->default('[]');
            $table->jsonb('objectives')->default('[]');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
            
            $table->unique(['account_id', 'slug']);
            $table->index('account_id');
            $table->index('product_id');
            $table->index('is_published');
            $table->index('is_featured');
        });
        }

        // Módulos do Curso
        if (!Schema::hasTable('course_modules')) {
        Schema::create('course_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('course_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->boolean('is_free')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->index('course_id');
            $table->index('sort_order');
        });
        }

        // Aulas
        if (!Schema::hasTable('lessons')) {
        Schema::create('lessons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('module_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->enum('content_type', ['video', 'text', 'quiz', 'download', 'live'])->default('video');
            
            // Vídeo
            $table->string('video_url')->nullable();
            $table->enum('video_provider', ['youtube', 'vimeo', 'bunny', 'custom'])->default('youtube');
            $table->string('video_id')->nullable();
            $table->integer('duration_seconds')->default(0);
            
            // Conteúdo
            $table->longText('content')->nullable();
            $table->jsonb('attachments')->default('[]');
            $table->jsonb('resources')->default('[]');
            
            $table->integer('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->boolean('is_free')->default(false);
            $table->boolean('is_downloadable')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('module_id')->references('id')->on('course_modules')->onDelete('cascade');
            $table->index('module_id');
            $table->index('sort_order');
            $table->index('is_published');
        });
        }

        // Matrículas/Inscrições
        if (!Schema::hasTable('enrollments')) {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('course_id');
            $table->uuid('charge_id')->nullable();
            $table->enum('status', ['active', 'expired', 'cancelled', 'pending'])->default('pending');
            $table->enum('access_type', ['lifetime', 'subscription', 'limited'])->default('lifetime');
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->integer('progress_percent')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->foreign('charge_id')->references('id')->on('charges')->onDelete('set null');
            
            $table->unique(['user_id', 'course_id']);
            $table->index('user_id');
            $table->index('course_id');
            $table->index('status');
            $table->index('expires_at');
        });
        }

        // Progresso das Aulas
        if (!Schema::hasTable('lesson_progress')) {
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('enrollment_id');
            $table->uuid('lesson_id');
            $table->integer('watched_seconds')->default(0);
            $table->integer('progress_percent')->default(0);
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_watched_at')->nullable();
            $table->jsonb('notes')->default('[]');
            $table->timestamps();

            $table->foreign('enrollment_id')->references('id')->on('enrollments')->onDelete('cascade');
            $table->foreign('lesson_id')->references('id')->on('lessons')->onDelete('cascade');
            
            $table->unique(['enrollment_id', 'lesson_id']);
            $table->index('enrollment_id');
            $table->index('lesson_id');
            $table->index('is_completed');
        });
        }

        // Membros (usuários da área de membros - separado dos users do gateway)
        if (!Schema::hasTable('members')) {
        Schema::create('members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id'); // Produtor
            $table->uuid('user_id')->nullable(); // Vinculação com user do gateway (opcional)
            $table->string('name');
            $table->string('email')->index();
            $table->string('password')->nullable();
            $table->string('avatar_url')->nullable();
            $table->string('phone')->nullable();
            $table->enum('status', ['active', 'inactive', 'blocked'])->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            
            $table->unique(['account_id', 'email']);
            $table->index('account_id');
            $table->index('status');
        });
        }

        // Certificados
        if (!Schema::hasTable('certificates')) {
        Schema::create('certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('enrollment_id');
            $table->uuid('course_id');
            $table->uuid('member_id');
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamp('issued_at');
            $table->string('pdf_url')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('enrollment_id')->references('id')->on('enrollments')->onDelete('cascade');
            $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->foreign('member_id')->references('id')->on('members')->onDelete('cascade');
            
            $table->index('code');
            $table->index('member_id');
        });
        }

        // Comentários nas aulas
        if (!Schema::hasTable('lesson_comments')) {
        Schema::create('lesson_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('lesson_id');
            $table->uuid('member_id');
            $table->uuid('parent_id')->nullable();
            $table->text('content');
            $table->boolean('is_approved')->default(true);
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('lesson_id')->references('id')->on('lessons')->onDelete('cascade');
            $table->foreign('member_id')->references('id')->on('members')->onDelete('cascade');
            
            $table->index('lesson_id');
            $table->index('member_id');
            $table->index('is_approved');
        });
        }

        // Self-referencing FK after table creation for PostgreSQL
        if (Schema::hasTable('lesson_comments')) {
        try {
        Schema::table('lesson_comments', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('lesson_comments')->onDelete('cascade');
        });
        } catch (\Exception $e) { /* FK already exists */ }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_comments');
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('members');
        Schema::dropIfExists('lesson_progress');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('course_modules');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('member_areas');
    }
};
