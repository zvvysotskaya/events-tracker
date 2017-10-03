<?php
namespace Tests;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class ThreadsTest extends TestCase
{

	public function setUp()
	{
		parent::setUp();

		$this->thread = factory('App\Thread')->create();
	}

    /** @test */
    function a_thread_has_a_path()
    {
        $thread = create('App\Thread');
        $this->assertEquals(
            "/threads/{$thread->id}", $thread->path()
        );
    }

    /**
	 * Test that threads are visible
     *
     * @test void
     */
    public function threads_browsable()
    {
        $response = $this->get('/threads');
    }


    /** @test */
    function a_user_can_read_a_single_thread()
    {
    	// when we visit a thread page
    	$this->get('/threads/' . $this->thread->id)
    		->assertSee($this->thread->name);
    }


    /** @test */
    function a_user_can_read_posts_that_are_associated_with_a_thread()
    {
    	// add that thread includes replies
    	$post = factory('App\Post')
    		->create(['thread_id' => $this->thread->id]);

    	// when we visit a thread page
    	$this->get('/threads/' . $this->thread->id)
    		->assertSee($post->body);
    }

     /** @test */
    function a_thread_has_a_creator()
    {
        // add that thread 
        $thread = factory('App\Thread')->create();
 
        $this->assertInstanceOf('App\User', $thread->creator);
    }

     /** @test */
    function a_thread_can_add_a_reply()
    {
        // add that thread 
        $this->thread->addPost([
            'body' => 'Foobar',
            'created_by' => 1
            ]);
    }


    /** @test */
    function an_authenticated_user_may_participate_in_threads()
    {
        // given we have an authenticated user
        $user = create('App\User');

        $this->be($user);

        $thread = create('App\Thread');

        $post = make('App\Post');

        $this->post('/threads/'.$thread->id.'/posts', $post->toArray());

        // then their reply should be visible
        $this->get($thread->path());
    }

    /** @test */
    function an_authenticated_user_can_create_new_forum_threads()
    {
        $this->signIn();

        $thread = factory('App\Thread')->make();

        $response = $this->post('/threads/create', $thread->toArray());

        $this->get($response->headers->get('Location'))
            ->assertSee($thread->name)
            ->assertSee($thread->body);

    }

    /** @test */
    function a_thread_requires_a_name()
    {
        $this->publishThread(['name' => null])
            ->assertSessionHasErrors('name');

    }

    /** @test */
    function a_thread_requires_a_body()
    {
        $this->publishThread(['body' => null])
            ->assertSessionHasErrors('body');

    }

    /** @test */
    function a_thread_requires_a_valid_forum()
    {
        $forum = factory('App\Forum')->create();

        $this->publishThread(['forum_id' => null])
            ->assertSessionHasErrors('forum_id');

        $this->publishThread(['forum_id' => 99999999])
            ->assertSessionHasErrors('forum_id');

    }

    public function publishThread($overrides = [])
    {
        $this->withExceptionHandling()->signIn();

        $thread = factory('App\Thread', $overrides)->make();

        $this->post('/threads', $thread->toArray());

        return $thread;
    }

    /** @test */
    function a_user_can_filter_threads_according_to_a_tag()
    {
        $category = create('App\ThreadCategory');
        $threadInCategory = create('App\Thread', ['thread_category_id' => $category->id]);
        $threadNotInCategory = create('App\Thread');
        
        $this->get('/threads/category/' . $category->name)
            ->assertSee($threadInCategory->name)
            ->assertDontSee($threadNotInCategory->name);
    }

}
