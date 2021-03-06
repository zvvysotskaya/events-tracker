<?php

namespace App\Filters;

class ThreadFilters extends QueryFilter
{
	public function name($value = NULL) // example.com/entity?name=<value>
	{
		if (isset($value))
		{
			return $this->builder->where('name', $value);
		} else {
			return $this->builder;
		}
	}

	public function thread_category($value = NULL) 
	{
		if (isset($value))
		{
			return $this->builder->where('thread_category', $value);
		} else {
			return $this->builder;
		}
	}

	protected function popular()
    {
        $this->builder->getQuery()->orders = [];

        return $this->builder->orderBy('posts_count', 'desc');
    }

}