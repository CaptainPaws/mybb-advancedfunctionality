<?php
declare(strict_types=1);
/** Structural guard only. Effects/Resolver remain the sole mechanical execution path. */
final class AF_ACS_CausalGraph
{
    public static function order(array $nodes,array $roots,int $maximumDepth):array
    {
        if($maximumDepth<1)throw new AF_ACS_FormulaError('ACS_CAUSAL_DEPTH_REQUIRED');
        $color=[];$post=[];
        foreach($roots as $root){$stack=[[$root,0]];
            while($stack){$top=count($stack)-1;[$id,$next]=$stack[$top];if(!isset($nodes[$id]))throw new AF_ACS_FormulaError('ACS_CAUSAL_REFERENCE_MISSING');
                if(!isset($color[$id]))$color[$id]=1;$children=$nodes[$id]['children'];
                if($next<count($children)){$child=$children[$next];$stack[$top][1]++;if(($color[$child]??0)===1)throw new AF_ACS_FormulaError('ACS_CAUSAL_CYCLE');if(($color[$child]??0)===0)$stack[]=[$child,0];}
                else{$color[$id]=2;$post[]=$id;array_pop($stack);}
            }
        }
        $height=[];foreach($post as $id){$height[$id]=1;foreach($nodes[$id]['children'] as $child)$height[$id]=max($height[$id],1+$height[$child]);}
        foreach($roots as $id)if($height[$id]>$maximumDepth)throw new AF_ACS_FormulaError('ACS_CAUSAL_DEPTH_EXCEEDED');
        $seen=[];$order=[];$stack=array_reverse($roots);while($stack){$id=array_pop($stack);if(isset($seen[$id]))continue;$seen[$id]=true;$order[]=$id;array_push($stack,...array_reverse($nodes[$id]['children']));}return $order;
    }
}
