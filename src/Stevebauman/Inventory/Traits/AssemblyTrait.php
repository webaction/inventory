<?php

namespace Stevebauman\Inventory\Traits;

use Illuminate\Database\Eloquent\Collection;

trait AssemblyTrait
{
    /**
     * The hasMany assemblies relationship.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    abstract public function assemblies();

    /**
     * Makes the current inventory item an assembly.
     *
     * @param bool $returnAssembly
     *
     * @return $this|bool|\Illuminate\Database\Eloquent\Model
     */
    public function makeAssembly($returnAssembly = false)
    {
        $assembly = $this->processCreateAssembly($this->id, $this->id);

        $this->is_assembly = true;
        $this->save();

        if($returnAssembly) {
            return $assembly;
        }

        return $this;
    }

    /**
     * Returns all of the assemblies items recursively.
     *
     * @param bool $recursive
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAssemblyItems($recursive = true)
    {
        /*
         * Grab all of the current item's assemblies
         * with a depth greater than 0 (indicating children)
         */
        $assemblies = $this->assemblies()->with('child')->where('depth', '>', 0)->get();

        $items = new Collection;

        // We'll go through each assembly
        foreach($assemblies as $assembly)
        {
            // Get the assembly child item
            $item = $assembly->child;

            if($item) {
                // Dynamically set the quantity attribute on the item
                $item->quantity = $assembly->quantity;

                // Add the item to the list of items if it exists
                $items->add($item);

                /*
                 * If the dev doesn't want a recursive
                 * query, we'll continue
                 */
                if( ! $recursive) continue;

                /*
                 * If the item is an assembly, we'll grab it's items into
                 * a new collection and add it to the current collection,
                 * creating a multi-dimensional nested collection array
                 */
                if($item->is_assembly) {
                    $nestedCollection = new Collection($item->getAssemblyItems()->toArray());

                    return $items->add($nestedCollection);
                }
            }
        }

        return $items;
    }

    /**
     * Adds an item to the current assembly.
     *
     * @param \Illuminate\Database\Eloquent\Model $part
     * @param int|string $quantity
     * @param null $depth
     * @param bool $returnPart
     *
     * @return bool|\Illuminate\Database\Eloquent\Model
     */
    public function addAssemblyItem($part, $quantity = 1, $depth = null, $returnPart = false)
    {
        /*
         * Make sure we make the current item an
         * assembly if it currently isn't one
         */
        if(! $this->is_assembly) $this->makeAssembly();

        if (is_null($depth)) {
            $depth = 1;
        }

        if($this->processCreateAssembly($this->id, $part->id, $depth, $quantity)) {
            return ($returnPart === true ? $part : $this);
        }

        return false;
    }

    /**
     * Scopes the current query to only retrieve
     * inventory items that are an assembly.
     *
     * @param $query
     *
     * @return mixed
     */
    public function scopeAssembly($query)
    {
        return $query->where('is_assembly', '=', true);
    }

    /**
     * Processes creating an inventory assembly.
     *
     * @param int|string $inventoryId
     * @param int|string $partId
     * @param int $depth
     * @param int|string $quantity
     *
     * @return bool|\Illuminate\Database\Eloquent\Model
     */
    private function processCreateAssembly($inventoryId, $partId, $depth = 0, $quantity = 0)
    {
        $this->dbStartTransaction();

        try {
            $assembly = $this->assemblies()->getRelated();

            $assembly->inventory_id = $inventoryId;
            $assembly->part_id = $partId;
            $assembly->depth = $depth;
            $assembly->quantity = (float) $quantity;

            if($assembly->save())
            {
                $this->dbCommitTransaction();

                $this->fireEvent('inventory.assembly.created', [
                    'item' => $this,
                    'assembly' => $assembly,
                ]);

                return $assembly;
            }
        } catch (\Exception $e) {
            $this->dbRollbackTransaction();
        }

        return false;
    }
}
