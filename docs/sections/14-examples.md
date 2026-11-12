## Examples

### 1. Clone an order as a template

Copy the items and their options, keep the products and the customer, and start the copy as a draft.

- ===Trait

  ```php title="app/Http/Controllers/OrderTemplateController.php"
  use Pharaonic\Laravel\DeepReplicate\RelationRule;

  public function store(Order $order)
  {
      $copy = $order->deepReplicate('items', 'items.options')
          ->reuse('items.product', 'customer')
          ->skip('payments')
          ->with(['status' => 'draft'])
          ->generate('slug', fn () => 'order-'.Str::lower(Str::random(10)))
          ->configure('items', fn (RelationRule $rule) => $rule->exclude(['external_id'])->with(['state' => 'pending']))
          ->replicate()
          ->model();

      return redirect()->route('orders.edit', $copy);
  }
  ```

- ===Facade

  ```php title="app/Http/Controllers/OrderTemplateController.php"
  use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
  use Pharaonic\Laravel\DeepReplicate\RelationRule;

  public function store(Order $order)
  {
      $copy = DeepReplicate::for($order)
          ->copy('items', 'items.options')
          ->reuse('items.product', 'customer')
          ->skip('payments')
          ->with(['status' => 'draft'])
          ->generate('slug', fn () => 'order-'.Str::lower(Str::random(10)))
          ->configure('items', fn (RelationRule $rule) => $rule->exclude(['external_id'])->with(['state' => 'pending']))
          ->replicate()
          ->model();

      return redirect()->route('orders.edit', $copy);
  }
  ```

### 2. Copy a catalog section with shared taxonomy

Products are copied with their variants; their tags stay shared, so only new pivot rows are written.

- ===Trait

  ```php
  $result = $category->deepReplicate('products', 'products.variants')
      ->reuse('products.tags')
      ->configure('products', fn (RelationRule $rule) => $rule->generate('sku', fn (Product $p) => $p->sku.'-'.$category->season))
      ->replicate();

  $result->stats()->pivotRowsCreated(); // one per original product-tag row
  ```

- ===Facade

  ```php
  $result = DeepReplicate::for($category)
      ->copy('products', 'products.variants')
      ->reuse('products.tags')
      ->configure('products', fn (RelationRule $rule) => $rule->generate('sku', fn (Product $p) => $p->sku.'-'.$category->season))
      ->replicate();

  $result->stats()->pivotRowsCreated(); // one per original product-tag row
  ```

### 3. Translate an article without duplicating its taxonomy

- ===Replication

  ```php title="app/Actions/CreateTranslation.php"
  public function handle(Article $article, string $locale): Article
  {
      return DeepReplicate::for($article)
          ->copy('sections', 'sections.blocks')
          ->reuse('categories', 'tags', 'author')
          ->with(['locale' => $locale, 'published_at' => null])
          ->generate('slug', fn (Article $source) => $source->slug.'-'.$locale)
          ->afterCommit(fn ($result) => TranslateArticle::dispatch($result->model()->getKey(), $locale))
          ->replicate()
          ->model();
  }
  ```

- ===Model

  ```php title="app/Article.php"
  class Article extends Model
  {
      use DeepReplicates;

      public function sections() { return $this->hasMany(Section::class); }
      public function categories() { return $this->belongsToMany(Category::class); }
      public function tags() { return $this->morphToMany(Tag::class, 'taggable'); }
      public function author() { return $this->belongsTo(User::class); }
  }
  ```

### 4. Check a large copy before running it

```php
$builder = fn () => DeepReplicate::for($project)->copy('tasks', 'tasks.checklist')->reuse('tasks.assignees');

$plan = $builder()->dryRun();

if ($plan->estimatedModels() > 5000) {
    return back()->withErrors('This project is too large to duplicate at once.');
}

$builder()->maxModels(5000)->replicate();
```

### 5. Stay inside the tenant

Keep global scopes on (the default), and copy the relations whose keys must change instead of retaining them:

- ===Trait

  ```php
  // The copy keeps team_id: duplicating within the same team.
  $board->deepReplicate('columns', 'columns.cards')->replicate();

  // Moving to another team: set the unmentioned key explicitly and check access first.
  Gate::authorize('create', [Board::class, $targetTeam]);
  $board->deepReplicate('columns', 'columns.cards')->with(['team_id' => $targetTeam->id])->replicate();
  ```

- ===Facade

  ```php
  // The copy keeps team_id: duplicating within the same team.
  DeepReplicate::for($board)->copy('columns', 'columns.cards')->replicate();

  // Moving to another team: set the unmentioned key explicitly and check access first.
  Gate::authorize('create', [Board::class, $targetTeam]);
  DeepReplicate::for($board)->copy('columns', 'columns.cards')->with(['team_id' => $targetTeam->id])->replicate();
  ```
