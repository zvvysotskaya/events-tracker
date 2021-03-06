<?php namespace App\Providers;

use App\Event;
use Illuminate\Support\ServiceProvider;

class ViewComposerServiceProvider extends ServiceProvider {

	/**
	 * Bootstrap the application services.
	 *
	 * @return void
	 */
	public function boot()
	{
		$this->composeNavigation();
	}

	/**
	 * Register the application services.
	 *
	 * @return void
	 */
	public function register()
	{
		//
	}

	/**
	 * Compose navigation bar
	 */
	private function composeNavigation()
	{
		view()->composer('partials.nav', function($view) {
			$view->with('latest', \App\Event::latest()->first());
			$view->with('roles', \App\Role::orderBy('name','ASC')->get());
		});
	}
}
