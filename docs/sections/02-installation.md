## Installation

Install the package with Composer. Laravel discovers the service provider and the `DeepReplicate` facade automatically.

### Requirements

- PHP 8.0 or 8.1
- Laravel 8.75 or newer within 8.x (`illuminate/database`, `illuminate/support`, `illuminate/container`, `illuminate/contracts`)

Each Laravel major has its own release line with the same API. Pick the line that matches your application (see [Compatibility](#compatibility)).

### Composer Installation

```bash title="Terminal" no-line-numbers
composer require pharaonic/laravel-deep-replicate
```

There is no configuration file to publish and no migration to run.

### Add the trait (optional)

The trait adds `deepReplicate()` to a model. The facade and the `DeepReplicator` service work on any model without it.

```php title="app/Models/Order.php"
use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\DeepReplicate\Concerns\DeepReplicates;

class Order extends Model
{
    use DeepReplicates;
}
```

:::success Installation Complete
You're all set. Select the relations to copy and call `replicate()`.
:::
