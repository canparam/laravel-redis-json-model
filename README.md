
<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>
<p align="center"><a href="https://github.com/redis-stack" target="_blank"><img src="https://avatars.githubusercontent.com/u/100624397?s=200&v=4" width="200" alt="Laravel Logo"></a></p>
If you use Redis as a database and have difficulty using queries, this package will be for you

**Important**

 - Not an official package
 - The way I write it may not be according to the standard design pattern

**Supports**
I always want to develop so that the code can get better. Please give me feedback if you see any problems

 - Thanks [redislabs-rejson](https://github.com/mkorkmaz/redislabs-rejson)

**Not support**

 - Relationship

**Next update plan**

 - Authentication support
 - Relationship support
 - Facade support

**Database**

 - [Redis Stack Server](https://github.com/redis-stack)
 - [Install Redis Stack](https://redis.io/docs/install/install-stack/)

**Install**

 1. Download lib
 2. Create **lib** in root project
 3. Add to composer.json
 
add to **require**

       "require": {  
        "ken/redisjsonmodel": "*@dev",
        ....
        }
add repositories

    "repositories": [  
        {  
            "type": "path",  
      "url": "./lib/*"  
      }  
    ]
run

    composer update
**Set in .env**
Prefix

    // example: simba:post:id
    REDIS_JSON_NAME=simba
    // predis or phpredis
    REDIS_CLIENT=predis 
    
<a href="https://ibb.co/1bCP9bP"><img src="https://i.ibb.co/b74Cr7C/12312.png" alt="12312" border="0"></a>
## Usage
**Create Index for Search**
add **CreateIndex**  Interface

     class Post extends Model implements CreateIndex  

function **getFielDataType**

    public function getFielDataType(): array  
    {  
        return [  
            'id' => [  
                'type' => DataType::INT->value,  
		      'index' => true  
      ],  
		      'title' => [  
                'type' => DataType::TEXT->value,  
			      'index' => true  
      ],  
      ];  
    }
**RUN Command**
--class = path class model

    php artisan create-index --class=App\\Models\\Redis\\Post

**The data is supported by indexing**

 - case INT = 'NUMERIC';  
 - case BOOL = 'boolean';  
 - case TEXT = 'TEXT';  
 - case STRING = 'STRING';  
 - case TAG = 'TAG';
For data type **Text**, it will always be a full text search

**Create Model**

    namespace App\Models\Redis;  
      
    use Ken\Contracts\CreateIndex;  
    use Ken\Enums\DataType;  
    use Ken\Models\Model;  
      
    class Post extends Model implements CreateIndex  
    {  
        public function prefix(): string  
      {  
            return 'post';  
      }  
      
        public $timestamps = true;  
	    protected $fillable = [  
            'id',  
		    'title',  
		    'content',  
      ];  
      
     public function getFielDataType(): array  
      {  
            return [  
                'id' => [  
                    'type' => DataType::INT->value,  
				    'index' => true  
			      ],  
			     'title' => [  
                    'type' => DataType::TEXT->value,  
				    'index' => true  
			      ],  
      ];  
      }  
    }

## Create / Update / Remove

**create**

    $post = new Post();  
      
    $post = $post->create([  
        'title' => 'Test create',  
      'content' => 'Hiha'  
    ]);

**update**

    $post = $post->firstById(1);  
    $post->content = "Change";  
    $post->save();
or

    $post->update([  
        'content' => "Change"  
    ]);
**Delete**

    $post->delete();


## QUERY
first

      //first by id
        $post = $post->firstById(1);
      //first where
        $post = $post->where('title','=','Redis')->first();
    
get

    $post = $post->where('title','=','Redis')->get();
paginate

    $post = $post->where('title','=','Redis')->paginate(20);

support : **in, >, <** 

    $post = $post->where('id','>',1)
    $post = $post->where('id','<',1)
    $post = $post->whereIn('id',[1,2,3,4])
    
raw query

    $post = $post->rawQuery('FT.SEARCH',...$param);


