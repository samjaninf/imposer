<?php
namespace dirtsimple\imposer\tests;

use dirtsimple\imposer\Bag;
use dirtsimple\imposer\HasMeta;
use dirtsimple\imposer\Mapper;
use dirtsimple\imposer\Model;
use dirtsimple\imposer\Promise;
use dirtsimple\imposer\Resource;
use dirtsimple\imposer\WatchedPromise;

use Brain\Monkey;
use Brain\Monkey\Functions as fun;
use GuzzleHttp\Promise as GP;
use Mockery;

class MockModel extends Model {

	use HasMeta;
	protected const meta_type='foo';

	# For mock saves
	public $save;
	protected function save() {
		return ($this->save)($this);
	}

	# To call protected methods
	public function call($method, ...$args){
		return $this->$method($args);
	}
}

describe("Mapper", function() {
	beforeEach( function() {
		$this->ref = new WatchedPromise;
		$this->model = new MockModel($this->ref);
		$this->mapper = new Mapper($this->model);
		$this->model->save = function($def) { return 42; };
	});

	describe("implements()", function(){
		it("is true iff its model is an instance of the class or interface", function(){
			expect($this->mapper->implements(Model::class))->to->be->true;
			expect($this->mapper->implements(Resource::class))->to->be->false;
		});
	});
	describe("has_method()", function(){
		it("is true iff its model has the method", function(){
			expect($this->mapper->has_method('call'))->to->be->true;
			expect($this->mapper->has_method('nosuchmethod'))->to->be->false;
		});
	});
	describe("apply()", function(){
		it("creates a new model each time", function(){
			$this->mapper->foo = 'bar';
			expect ( $this->model->items()  )->to->equal( array('foo'=>'bar') );
			expect ( $this->mapper->items() )->to->equal( array('foo'=>'bar') );
			$this->mapper->apply();
			expect ( $this->mapper->items() )->to->equal( array() );
			expect ( $this->model->items()  )->to->equal( array('foo'=>'bar') );
		});
		it("calls its model's apply() after the previous one resolves", function(){
			$this->log = array(); $p1 = new GP\Promise(); $p2 = new GP\Promise();
			$this->mapper->save = function() use ($p1) { $this->log[] = 'save 1'; return $p1; };

			# First apply: save started but hung on p1, so not resolved yet
			$a1 = $this->mapper->apply();
			Promise::sync();
			expect($this->log)->to->equal(array('save 1'));
			expect( Promise::now($a1, false) )->to->be->false;

			# Second apply, save shouldn't have run because a1 isn't done
			$this->mapper->save = function() use ($p2) { $this->log[] = 'save 2'; return $p2; };
			$a2 = $this->mapper->apply();
			expect($this->log)->to->equal(array('save 1'));
			expect( Promise::now($a1, false) )->to->equal(false);
			expect( Promise::now($a2, false) )->to->equal(false);

			# Resolving p1 finishes a1's save and a1 in general, starting a2 save
			$p1->resolve(42); Promise::sync();
			expect( Promise::now($a1, false) )->to->equal(42);
			expect( Promise::now($a2, false) )->to->equal(false);
			expect($this->log)->to->equal(array('save 1', 'save 2'));

			# Resolving p2 allows a2 to finish
			$p2->resolve(99); Promise::sync();
			expect( Promise::now($a2, false) )->to->equal(99);
		});
	});
	describe("delegates to its model methods and", function(){
		class DelegateTestModel extends MockModel {
			function yieldingMethod() {
				yield 42; yield 26; yield "hut!";
			}
			function rejectingMethod() {
				return Promise::error("blue 62");
			}
		}
		beforeEach( function() {
			$this->model = new DelegateTestModel($this->ref);
			$this->mapper = new Mapper($this->model);
		});
		it("Promise::interpret()s the results", function(){
			expect( $this->mapper->yieldingMethod() )->to->equal('hut!');
		});
		it("throws errors for returned promise rejections", function(){
			expect( array($this->mapper, 'rejectingMethod') )->to->throw(
				"Exception", "The promise was rejected with reason: blue 62"
			);
		});
		it("returns itself for chainable methods", function(){
			expect( $this->mapper->call('also', null) )->to->equal($this->mapper);
		});
		it("properties, offsets, and count", function(){
			$mapper = $this->mapper; $model = $this->model;
			$mapper->x = 'y';
			$mapper['q'] = 'r';
			expect( isset($mapper->q) )->to->be->true;
			expect( isset($mapper['x']) )->to->be->true;
			expect( isset($mapper->y) )->to->be->false;
			expect( isset($mapper['z']) )->to->be->false;
			expect( $mapper->q )->to->equal('r');
			expect( $mapper['x'] )->to->equal('y');
			expect( $model->items() )->to->equal( array('x'=>'y', 'q'=>'r') );
			expect( count($mapper) )->to->equal(2);
			expect( $mapper->offsetExists('x') )->to->be->true;
			expect( $mapper->offsetExists('a') )->to->be->false;
			expect( (array) $mapper->getIterator() )->to->equal( array('x'=>'y', 'q'=>'r') );
			unset($mapper->q);
			expect( $model->items() )->to->equal( array('x'=>'y') );
			unset($mapper['x']);
			expect( $model->items() )->to->equal( array() );
		});
	});

});

describe("Model", function() {
	beforeEach( function() {
		$this->ref = new WatchedPromise;
		$this->model = new MockModel($this->ref);
	});

	it("is a Bag", function(){
		expect( $this->model )->to->be->instanceof(Bag::class);
	});

	describe("::configure() in subclasses adds lookups such that", function(){
		class Lookup1 extends MockModel {
			static function lookup() {}
		}
		class Lookup2 extends MockModel {
			static function lookup() {}
			static function lookup_by_path() {}
			static function lookup_by_guid() {}
		}

		beforeEach( function() {
			$this->resource = Mockery::Mock(Resource::class);
			$this->model = new MockModel($this->ref);
		});
		afterEach( function(){
			Monkey\tearDown();
		});

		it("lookup() method becomes a lookup handler", function() {
			$res = $this->resource;
			$res->shouldReceive('addLookup')->with(array(Lookup1::class, 'lookup'))->once();
			Lookup1::configure($this->resource);
		});

		it("lookup_by_X() methods become lookup handlers for key type X", function() {
			$res = $this->resource;
			$res->shouldReceive('addLookup')->with(array(Lookup2::class, 'lookup'))->once();
			$res->shouldReceive('addLookup')->with(array(Lookup2::class, 'lookup_by_path'), 'path')->once();
			$res->shouldReceive('addLookup')->with(array(Lookup2::class, 'lookup_by_guid'), 'guid')->once();
			Lookup2::configure($this->resource);
		});
	});

	describe("id()", function(){
		it("returns a default if its key wasn't found", function(){
			expect($this->model->id())->to->be->null;
		});
		it("returns a value once the promise is resolved", function(){
			$this->ref->resolve(42);
			expect($this->model->id())->to->equal(42);
		});
	});

	describe("ref()", function(){
		it("returns the Reference (promise) it was created from", function(){
			expect($this->model->ref())->to->equal($this->ref);
			$this->ref->resolve(42);  # even after resolution
			expect($this->model->ref())->to->equal($this->ref);
		});
	});

	describe("apply()", function(){
		it("calls save() with all promised bag contents resolved", function(){
			$this->props=array();
			$this->model->set(array('a'=>42, 'b'=>Promise::value(99)));
			$this->model->save=function($def) { $this->props = (array) $def; };
			$res = Promise::interpret($this->model->apply());
			expect($this->props)->to->equal(array('a'=>42,'b'=>99));
		});
		it("resolves its reference promise with the result from save()", function(){
			$this->model->set(array('a'=>42, 'b'=>Promise::value(99)));
			$this->model->save=function($def) { return 42; };
			expect($this->model->id())->to->equal(null);
			$res = Promise::interpret($this->model->apply());
			expect($this->model->id())->to->equal(42);
		});
		it("waits for all promises given to also() before resolving", function(){
			$this->model->save=function($def) { return 42; };
			$this->model->call('also', $p1=new GP\Promise);
			$this->model->call('also', $p2=new GP\Promise);
			$res = Promise::interpret($this->model->apply());

			expect( Promise::now($res) )->to->be->null;
			$p1->resolve(1); Promise::sync();
			expect( Promise::now($res) )->to->be->null;
			$p2->resolve(2); Promise::sync();
			expect( Promise::now($res) )->to->equal(42);
		});
	});

	describe("also()", function(){
		it("returns \$this", function (){
			expect($this->model->call('also', 42))->to->equal($this->model);
		});
		describe("starts coroutines", function(){
			it("after the previous model completes", function(){
				$this->flag = false;
				$new = $this->model->next($p = new GP\Promise());
				$new->call('also', function(){
					yield 16; $this->flag = true;
				});
				expect($this->flag)->to->be->false;
				$p->resolve(null); Promise::sync();
				expect($this->flag)->to->be->true;
			});
			it("immediately if no previous model", function(){
				$this->flag = false;
				$this->model->call('also', function(){
					yield 16; $this->flag = true;
				});
				expect($this->flag)->to->be->true;
			});
		});
	});

	describe("settle_args()", function(){
		it("returns a promise for resolving all internal promises", function(){
			$this->model->set(array('a'=>42, 'b'=>Promise::value(99)));
			$res = Promise::interpret($this->model->call('settle_args'));
			Promise::sync();
			expect((array)$this->model)->to->equal(array('a'=>42,'b'=>99));
		});
	});

	describe("next()", function(){
		it("returns a new empty instance with the same class and ref()", function (){
			$this->model->foo = 22;
			$new = $this->model->next($p=new GP\Promise());
			expect($new)->to->be->instanceof(get_class($this->model));
			expect( $new->ref() )->to->equal($this->model->ref());
			expect( (array) $this->model )->to->equal( array('foo'=>22) );
			expect( (array) $new         )->to->equal( array() );
		});
	});

	describe("with HasMeta", function(){
		describe("set_meta()", function(){
			beforeEach( function() {
				$this->log=array();
				$this->mock_meta=array();
				$this->expected = [];
				$this->expect = function(...$args) { $this->expected[] = $args; };
				$this->model->save = function($def) { return 42; };
				$this->check = function($meta=null) {
					expect($this->log)->to->equal($this->expected);
					if ($meta !== null)
						expect($this->mock_meta)->to->equal($meta);
				};
				fun\stubs(array(
					'update_foo_meta' => function($id, $key, $val){
						$this->log[] = array('set', $id, $key, $val);
						$this->mock_meta[$id][$key]=$val;
					},
					'get_foo_meta' => function($id, $key, $true){
						$this->log[] = array('get', $id, $key, $true);
						return $this->mock_meta[$id][$key];
					},
				));
			});
			afterEach( function(){
				$this->check();
				Monkey\tearDown();
			});

			it("is disabled unless a `meta_type` constant is set", function(){
				class NoMetaModel extends Model {
					use HasMeta;
					function save(){}
				}
				$model = new NoMetaModel($this->ref);
				expect( array($model, 'set_meta') )->with('x', 'y')->to->throw(
					"Exception", 'dirtsimple\imposer\tests\NoMetaModel does not support metadata'
				);
			});

			it("rejects null, empty, or non-string keys", function(){
				expect( array($this->model, 'set_meta') )->with(null, 'y')->to->throw(
					"Exception", "meta_key must not be empty"
				);
				expect( array($this->model, 'set_meta') )->with(array(), 'y')->to->throw(
					"Exception", "meta_key must not be empty"
				);
				expect( array($this->model, 'set_meta') )->with('', 'y')->to->throw(
					"Exception", "meta_key items must be non-empty strings"
				);
				expect( array($this->model, 'set_meta') )->with(2, 'y')->to->throw(
					"Exception", "meta_key items must be non-empty strings"
				);
				expect( array($this->model, 'set_meta') )->with(array("x", 4.6), 'y')->to->throw(
					"Exception", "meta_key items must be non-empty strings"
				);
				expect( array($this->model, 'set_meta') )->with(array('','x'), 'y')->to->throw(
					"Exception", "meta_key items must be non-empty strings"
				);
				expect( array($this->model, 'set_meta') )->with(array('x',''), 'y')->to->throw(
					"Exception", "meta_key items must be non-empty strings"
				);
			});
			it("returns self", function(){
				$this->ref->resolve(42);  # run sync so we don't have to apply()
				$this->expect('set', 42, 'x', 'y');
				expect( $this->model->set_meta('x', 'y') )->to->equal($this->model);
			});

			it("calls _set_meta() if key is a string or single-element array", function(){
				$this->ref->resolve(42);  # run sync so we don't have to apply()
				$this->expect('set', 42, 'aKey', array('some','thing'));
				$this->model->set_meta(array('aKey'), array('some', Promise::value('thing')));
				$this->check();  # should synchronously apply and set meta

				$this->expect('set', 42, 'aKey', array('another','thing'));
				$this->model->set_meta(array('aKey'), array($p=new GP\Promise(), 'thing'));
				$p->resolve('another');
				Promise::sync();  # promised, so async
			});

			it("patches meta values if key is a multi-element array", function(){
				$this->ref->resolve(42);  # run sync so we don't have to apply()
				$this->expect('set', 42, 'srcKey', false);
				$this->model->set_meta('srcKey', false);
				$this->expect('get', 42, 'srcKey', true);
				$this->expect('set', 42, 'srcKey', array('some'=>array('thing'=>'else')));
				$this->model->set_meta(array('srcKey', 'some', 'thing'), Promise::value('else'));
				$this->check();  # should synchronously apply and resolve

				$this->expect('get', 42, 'srcKey', true);
				$this->expect('set', 42, 'srcKey', array('some'=>array('thing'=>'else', 'and'=>'more')));
				$this->model->set_meta(array('srcKey', 'some', 'and'), 'more');
			});

			it("delays the fulfillment of apply(), if it has to wait", function(){
				$this->expect('set', 42, 'aKey', array('another','thing'));
				$this->model->set_meta(array('aKey'), array($p=new GP\Promise(), 'thing'));
				$res = Promise::interpret( $this->model->apply() ); Promise::sync();
				expect( Promise::now($res) )->to->be->null;
				$p->resolve('another'); Promise::sync();
				expect( Promise::now($res) )->to->equal(42);
			});
		});
	});

});
