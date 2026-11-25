import test from 'node:test';
import assert from 'node:assert/strict';
import { emptyState } from '../../resources/js/empty-state.js';

const escape=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
test('empty/error copy is escaped and retry preserves the existing action contract',()=>{
    const html=emptyState({title:'<script>bad</script>',message:'<img src=x>',retry:true,signedIn:true,escape});
    assert.doesNotMatch(html,/<script>|<img/);assert.match(html,/&lt;script&gt;/);assert.match(html,/data-feed-action="retry"/);
    assert.doesNotMatch(html,/tanpa masuk/);assert.match(html,/aria-hidden="true"/);
});
