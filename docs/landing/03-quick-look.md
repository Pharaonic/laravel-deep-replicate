---
view: components.packages.quick-look
title: A quick look
subtitle: Copy an order with its items and options, keep the products and the customer.
file: app/Http/Controllers/OrderController.php
language: php
code: |
  use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;

  $result = DeepReplicate::for($order)
      ->copy('items', 'items.options')
      ->reuse('items.product', 'customer')
      ->skip('payments')
      ->with(['status' => 'draft'])
      ->replicate();

  $copy = $result->model();                  // the new, persisted order
  $newItem = $result->map()->get($oldItem);  // its copy
  $result->stats()->copiedModels();          // root + items + options
---
