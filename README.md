<p align="center"><a href="https://pharaonic.dev/packages/laravel/deep-replicate" target="_blank"><img src="https://raw.githubusercontent.com/Pharaonic/laravel-deep-replicate/develop/docs/cover.webp"></a></p>
<p align="center">
  <a href="https://php.net" target="_blank"><img src="https://img.shields.io/static/v1?label=PHP&message=8.3%20|%208.4%20|%208.5&color=blue&style=flat-square" alt="PHP Version : 8.3 | 8.4 | 8.5"></a>
  <a href="https://laravel.com" target="_blank"><img src="https://img.shields.io/static/v1?label=Laravel&message=13.x&color=F05340&style=flat-square" alt="Laravel Version : 13.x"></a>
  <img src="https://img.shields.io/static/v1?label=License&message=MIT&color=brightgreen&style=flat-square" alt="License">
  <img src="https://github.com/Pharaonic/laravel-deep-replicate/actions/workflows/build.yml/badge.svg?branch=13.x" alt="Build">
  <br>
  <a href="https://packagist.org/packages/Pharaonic/laravel-deep-replicate" target="_blank"><img src="https://img.shields.io/static/v1?label=Packagist&message=pharaonic/laravel-deep-replicate&color=blue&logo=packagist&logoColor=white" alt="Source"></a>
  <a href="https://packagist.org/packages/pharaonic/laravel-deep-replicate" target="_blank"><img src="https://poser.pugx.org/pharaonic/laravel-deep-replicate/v" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/pharaonic/laravel-deep-replicate" target="_blank"><img src="https://poser.pugx.org/pharaonic/laravel-deep-replicate/downloads" alt="Packagist Downloads"></a>
</p>

<h3 align="center">Replicate a chosen Eloquent relationship graph into new records, with explicit copy, reuse and skip rules.</h3>
<br>

## Installation

```bash
composer require pharaonic/laravel-deep-replicate
```

The service provider and the `DeepReplicate` facade are registered automatically. There is nothing to publish or migrate.

## Usage

```php
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;

$result = DeepReplicate::for($order)
    ->copy('items', 'items.options')   // new items and options for the new order
    ->reuse('items.product', 'customer') // link to the existing products and customer
    ->skip('payments')
    ->with(['status' => 'draft'])
    ->replicate();                      // one transaction

$copy = $result->model();               // the new, persisted order
$result->map()->get($oldItem);          // the copy of an original item
$result->stats()->copiedModels();       // root + items + options
```

Or add the trait to the model and start from the model itself; it returns the same builder:

```php
use Pharaonic\Laravel\DeepReplicate\Concerns\DeepReplicates;

class Order extends Model
{
    use DeepReplicates;
}

$result = $order->deepReplicate('items', 'items.options')
    ->reuse('items.product', 'customer')
    ->replicate();
```

Laravel's own `replicate()` is untouched. At least one relation must always be selected.

## Documentation

Full documentation: [Deep Replicate Documentation](https://pharaonic.dev/packages/laravel/deep-replicate).

## Support & Security

See [SUPPORT.md](SUPPORT.md) and [SECURITY.md](SECURITY.md). Contributions are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
