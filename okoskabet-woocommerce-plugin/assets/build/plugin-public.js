(()=>{"use strict";window.onload=()=>{jQuery("#example-demo-button").on("click",(function(){jQuery.ajax({method:"POST",url:window.location+"wp-json/wp/v2/demo/example",data:{nonce:window.exampleDemo.nonce},beforeSend(e){e.setRequestHeader("X-WP-Nonce",window.exampleDemo.wp_rest)}}).done((function(){window.location.reload()})).fail((function(){alert(window.exampleDemo.alert)}))}))}})();