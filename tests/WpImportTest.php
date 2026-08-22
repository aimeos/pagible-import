<?php

/**
 * @license MIT, https://opensource.org/license/mit
 */


namespace Tests;

use Aimeos\Cms\Models\Page;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


class WpImportTest extends ImportTestAbstract
{
    use CmsWithMigrations;
    use RefreshDatabase;


    protected function defineEnvironment( $app )
    {
        parent::defineEnvironment( $app );

        $app['config']->set( 'database.connections.wordpress', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ] );
    }


    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection( 'wordpress' )->create( 'wp_posts', function( Blueprint $table ) {
            $table->id( 'ID' );
            $table->string( 'post_type' );
            $table->string( 'post_status' );
            $table->dateTime( 'post_date' );
            $table->string( 'post_name' );
            $table->string( 'post_title' );
            $table->text( 'post_excerpt' );
            $table->text( 'post_content' );
            $table->string( 'post_mime_type' )->default( '' );
            $table->string( 'guid' )->default( '' );
        } );

        Schema::connection( 'wordpress' )->create( 'wp_postmeta', function( Blueprint $table ) {
            $table->unsignedBigInteger( 'post_id' );
            $table->string( 'meta_key' );
            $table->text( 'meta_value' )->nullable();
        } );
    }


    public function testUpdatesExistingArticleBelowDomainBlog(): void
    {
        $oldRoot = $this->page( 'Old root', '', 'old.example', 'root' );
        $oldBlog = $this->page( 'Tips', 'tips', 'old.example', 'page', $oldRoot );
        $root = $this->page( 'New root', '', 'new.example', 'root' );
        $blog = $this->page( 'Tips', 'tips', 'new.example', 'page', $root );

        DB::connection( 'wordpress' )->table( 'wp_posts' )->insert( [
            'ID' => 1,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date' => '2025-01-02 03:04:05',
            'post_name' => 'example-post',
            'post_title' => 'Original title',
            'post_excerpt' => 'Original introduction',
            'post_content' => '<p>Original body</p>',
            'post_mime_type' => '',
            'guid' => '',
        ] );

        $result = Artisan::call( 'cms:wp-import', [
            '--connection' => 'wordpress',
            '--domain' => 'new.example',
            '--blog-path' => 'tips',
        ] );

        $this->assertSame( 0, $result );

        $article = Page::where( 'domain', 'new.example' )->where( 'path', 'example-post' )->firstOrFail();

        $this->assertSame( $blog->id, $article->parent_id );
        $this->assertSame( 0, Page::where( 'parent_id', $oldBlog->id )->count() );
        $this->assertSame( 1, $article->versions()->count() );

        $article->appendToNode( Page::whereKey( $root->id )->firstOrFail() )->save();

        DB::connection( 'wordpress' )->table( 'wp_posts' )->where( 'ID', 1 )->update( [
            'post_date' => '2026-02-03 04:05:06',
            'post_title' => 'Updated title',
            'post_excerpt' => 'Updated introduction',
            'post_content' => '<p>Updated body</p>',
        ] );

        $result = Artisan::call( 'cms:wp-import', [
            '--connection' => 'wordpress',
            '--domain' => 'new.example',
            '--blog-path' => 'tips',
        ] );

        $this->assertSame( 0, $result );

        $updated = Page::where( 'domain', 'new.example' )->where( 'path', 'example-post' )->firstOrFail();
        $content = (array) $updated->content;

        $this->assertSame( $article->id, $updated->id );
        $this->assertSame( $blog->id, $updated->parent_id );
        $this->assertSame( 'Updated title', $updated->title );
        $this->assertSame( 'Updated introduction', $content[0]->data->text ?? null );
        $this->assertSame( 'Updated body', $content[1]->data->text ?? null );
        $this->assertSame( 1, Page::where( 'domain', 'new.example' )->where( 'path', 'example-post' )->count() );
        $this->assertSame( 2, $updated->versions()->count() );
    }


    protected function page( string $name, string $path, string $domain, string $tag, ?Page $parent = null ): Page
    {
        $page = Page::forceCreate( [
            'name' => $name,
            'title' => $name,
            'path' => $path,
            'domain' => $domain,
            'lang' => 'en',
            'tag' => $tag,
            'status' => 1,
            'editor' => 'test',
        ] );

        if( $parent ) {
            $page->appendToNode( $parent )->save();
        }

        return $page;
    }
}
