(()=>{"use strict";new Set;const e="undefined"!=typeof window?window:"undefined"!=typeof globalThis?globalThis:global;class t{_listeners="WeakMap"in e?new WeakMap:void 0;_observer=void 0;options;constructor(e){this.options=e}observe(e,t){return this._listeners.set(e,t),this._getObserver().observe(e,this.options),()=>{this._listeners.delete(e),this._observer.unobserve(e)}}_getObserver(){return this._observer??(this._observer=new ResizeObserver((e=>{for(const n of e)t.entries.set(n.target,n),this._listeners.get(n.target)?.(n)})))}}t.entries="WeakMap"in e?new WeakMap:void 0;let n,o=!1;function s(e,t){e.appendChild(t)}function i(e,t,n){e.insertBefore(t,n||null)}function l(e){e.parentNode&&e.parentNode.removeChild(e)}function r(e,t){for(let n=0;n<e.length;n+=1)e[n]&&e[n].d(t)}function c(e){return document.createElement(e)}function d(e){return document.createTextNode(e)}function a(){return d(" ")}function u(){return d("")}function p(e,t,n,o){return e.addEventListener(t,n,o),()=>e.removeEventListener(t,n,o)}function h(e,t,n){null==n?e.removeAttribute(t):e.getAttribute(t)!==n&&e.setAttribute(t,n)}function f(e,t){t=""+t,e.data!==t&&(e.data=t)}function $(e,t){e.value=null==t?"":t}function m(e,t,n,o){null==n?e.style.removeProperty(t):e.style.setProperty(t,n,o?"important":"")}function g(e,t,n){for(let n=0;n<e.options.length;n+=1){const o=e.options[n];if(o.__value===t)return void(o.selected=!0)}n&&void 0===t||(e.selectedIndex=-1)}function v(e){const t=e.querySelector(":checked");return t&&t.__value}function y(e,t,n){e.classList.toggle(t,!!n)}function _(){}function k(e){return e()}function b(){return Object.create(null)}function w(e){e.forEach(k)}function S(e){return"function"==typeof e}function D(e,t){return e!=e?t==t:e!==t||e&&"object"==typeof e||"function"==typeof e}function x(e){return null==e?"":e}function C(e){n=e}function M(){if(!n)throw new Error("Function called outside component initialization");return n}function E(e){M().$$.on_mount.push(e)}function L(e){M().$$.on_destroy.push(e)}new Map;const I=[],O=[];let j=[];const A=[],F=Promise.resolve();let T=!1;function N(e){j.push(e)}const B=new Set;let P=0;function z(){if(0!==P)return;const e=n;do{try{for(;P<I.length;){const e=I[P];P++,C(e),H(e.$$)}}catch(e){throw I.length=0,P=0,e}for(C(null),I.length=0,P=0;O.length;)O.pop()();for(let e=0;e<j.length;e+=1){const t=j[e];B.has(t)||(B.add(t),t())}j.length=0}while(I.length);for(;A.length;)A.pop()();T=!1,B.clear(),C(e)}function H(e){if(null!==e.fragment){e.update(),w(e.before_update);const t=e.dirty;e.dirty=[-1],e.fragment&&e.fragment.p(e.ctx,t),e.after_update.forEach(N)}}const V=new Set;let q,Q;function W(){q={r:0,c:[],p:q}}function R(){q.r||w(q.c),q=q.p}function U(e,t){e&&e.i&&(V.delete(e),e.i(t))}function J(e,t,n,o){if(e&&e.o){if(V.has(e))return;V.add(e),q.c.push((()=>{V.delete(e),o&&(n&&e.d(1),o())})),e.o(t)}else o&&o()}function Y(e,t){const n=t.token={};function o(e,o,s,i){if(t.token!==n)return;t.resolved=i;let l=t.ctx;void 0!==s&&(l=l.slice(),l[s]=i);const r=e&&(t.current=e)(l);let c=!1;t.block&&(t.blocks?t.blocks.forEach(((e,n)=>{n!==o&&e&&(W(),J(e,1,1,(()=>{t.blocks[n]===e&&(t.blocks[n]=null)})),R())})):t.block.d(1),r.c(),U(r,1),r.m(t.mount(),t.anchor),c=!0),t.block=r,t.blocks&&(t.blocks[o]=r),c&&z()}if(!(s=e)||"object"!=typeof s&&"function"!=typeof s||"function"!=typeof s.then){if(t.current!==t.then)return o(t.then,1,t.value,e),!0;t.resolved=e}else{const n=M();if(e.then((e=>{C(n),o(t.then,1,t.value,e),C(null)}),(e=>{if(C(n),o(t.catch,2,t.error,e),C(null),!t.hasCatch)throw e})),t.current!==t.pending)return o(t.pending,0),!0}var s}function Z(e,t,n){const o=t.slice(),{resolved:s}=e;e.current===e.then&&(o[e.value]=s),e.current===e.catch&&(o[e.error]=s),e.block.p(o,n)}function G(e){return void 0!==e?.length?e:Array.from(e)}function X(e){e&&e.c()}function K(e,t,n){const{fragment:o,after_update:s}=e.$$;o&&o.m(t,n),N((()=>{const t=e.$$.on_mount.map(k).filter(S);e.$$.on_destroy?e.$$.on_destroy.push(...t):w(t),e.$$.on_mount=[]})),s.forEach(N)}function ee(e,t){const n=e.$$;null!==n.fragment&&(function(e){const t=[],n=[];j.forEach((o=>-1===e.indexOf(o)?t.push(o):n.push(o))),n.forEach((e=>e())),j=t}(n.after_update),w(n.on_destroy),n.fragment&&n.fragment.d(t),n.on_destroy=n.fragment=null,n.ctx=[])}function te(e,t,s,i,r,c,d=null,a=[-1]){const u=n;C(e);const p=e.$$={fragment:null,ctx:[],props:c,update:_,not_equal:r,bound:b(),on_mount:[],on_destroy:[],on_disconnect:[],before_update:[],after_update:[],context:new Map(t.context||(u?u.$$.context:[])),callbacks:b(),dirty:a,skip_bound:!1,root:t.target||u.$$.root};d&&d(p.root);let h=!1;if(p.ctx=s?s(e,t.props||{},((t,n,...o)=>{const s=o.length?o[0]:n;return p.ctx&&r(p.ctx[t],p.ctx[t]=s)&&(!p.skip_bound&&p.bound[t]&&p.bound[t](s),h&&function(e,t){-1===e.$$.dirty[0]&&(I.push(e),T||(T=!0,F.then(z)),e.$$.dirty.fill(0)),e.$$.dirty[t/31|0]|=1<<t%31}(e,t)),n})):[],p.update(),h=!0,w(p.before_update),p.fragment=!!i&&i(p.ctx),t.target){if(t.hydrate){o=!0;const e=(f=t.target,Array.from(f.childNodes));p.fragment&&p.fragment.l(e),e.forEach(l)}else p.fragment&&p.fragment.c();t.intro&&U(e.$$.fragment),K(e,t.target,t.anchor),o=!1,z()}var f;C(u)}function ne(e,t,n,o){const s=n[e]?.type;if(t="Boolean"===s&&"boolean"!=typeof t?null!=t:t,!o||!n[e])return t;if("toAttribute"===o)switch(s){case"Object":case"Array":return null==t?null:JSON.stringify(t);case"Boolean":return t?"":null;case"Number":return null==t?null:t;default:return t}else switch(s){case"Object":case"Array":return t&&JSON.parse(t);case"Boolean":default:return t;case"Number":return null!=t?+t:t}}new Set(["allowfullscreen","allowpaymentrequest","async","autofocus","autoplay","checked","controls","default","defer","disabled","formnovalidate","hidden","inert","ismap","loop","multiple","muted","nomodule","novalidate","open","playsinline","readonly","required","reversed","selected"]),"function"==typeof HTMLElement&&(Q=class extends HTMLElement{$$ctor;$$s;$$c;$$cn=!1;$$d={};$$r=!1;$$p_d={};$$l={};$$l_u=new Map;constructor(e,t,n){super(),this.$$ctor=e,this.$$s=t,n&&this.attachShadow({mode:"open"})}addEventListener(e,t,n){if(this.$$l[e]=this.$$l[e]||[],this.$$l[e].push(t),this.$$c){const n=this.$$c.$on(e,t);this.$$l_u.set(t,n)}super.addEventListener(e,t,n)}removeEventListener(e,t,n){if(super.removeEventListener(e,t,n),this.$$c){const e=this.$$l_u.get(t);e&&(e(),this.$$l_u.delete(t))}}async connectedCallback(){if(this.$$cn=!0,!this.$$c){if(await Promise.resolve(),!this.$$cn||this.$$c)return;function e(e){return()=>{let t;return{c:function(){t=c("slot"),"default"!==e&&h(t,"name",e)},m:function(e,n){i(e,t,n)},d:function(e){e&&l(t)}}}}const t={},n=function(e){const t={};return e.childNodes.forEach((e=>{t[e.slot||"default"]=!0})),t}(this);for(const s of this.$$s)s in n&&(t[s]=[e(s)]);for(const r of this.attributes){const d=this.$$g_p(r.name);d in this.$$d||(this.$$d[d]=ne(d,r.value,this.$$p_d,"toProp"))}for(const a in this.$$p_d)a in this.$$d||void 0===this[a]||(this.$$d[a]=this[a],delete this[a]);this.$$c=new this.$$ctor({target:this.shadowRoot||this,props:{...this.$$d,$$slots:t,$$scope:{ctx:[]}}});const o=()=>{this.$$r=!0;for(const e in this.$$p_d)if(this.$$d[e]=this.$$c.$$.ctx[this.$$c.$$.props[e]],this.$$p_d[e].reflect){const t=ne(e,this.$$d[e],this.$$p_d,"toAttribute");null==t?this.removeAttribute(this.$$p_d[e].attribute||e):this.setAttribute(this.$$p_d[e].attribute||e,t)}this.$$r=!1};this.$$c.$$.after_update.push(o),o();for(const u in this.$$l)for(const p of this.$$l[u]){const f=this.$$c.$on(u,p);this.$$l_u.set(p,f)}this.$$l={}}}attributeChangedCallback(e,t,n){this.$$r||(e=this.$$g_p(e),this.$$d[e]=ne(e,n,this.$$p_d,"toProp"),this.$$c?.$set({[e]:this.$$d[e]}))}disconnectedCallback(){this.$$cn=!1,Promise.resolve().then((()=>{!this.$$cn&&this.$$c&&(this.$$c.$destroy(),this.$$c=void 0)}))}$$g_p(e){return Object.keys(this.$$p_d).find((t=>this.$$p_d[t].attribute===e||!this.$$p_d[t].attribute&&t.toLowerCase()===e))||e}});class oe{$$=void 0;$$set=void 0;$destroy(){ee(this,1),this.$destroy=_}$on(e,t){if(!S(t))return _;const n=this.$$.callbacks[e]||(this.$$.callbacks[e]=[]);return n.push(t),()=>{const e=n.indexOf(t);-1!==e&&n.splice(e,1)}}$set(e){var t;this.$$set&&(t=e,0!==Object.keys(t).length)&&(this.$$.skip_bound=!0,this.$$set(e),this.$$.skip_bound=!1)}}async function se(e,t,n){const o=new URLSearchParams({zip:n,address:encodeURIComponent(t)}).toString(),s=new Headers;s.append("Accept","application/json"),s.append("Content-Type","application/json");const i={method:"GET",headers:s,redirect:"follow"},l=function(e){switch(e){case"shed-delivery":return"/wp-json/wp/v2/okoskabet/sheds?";case"home-delivery":return"/wp-json/wp/v2/okoskabet/home_delivery?"}}(e)+o,r=await fetch(l,i),{results:c}=await r.json();return{...c,type:e}}"undefined"!=typeof window&&(window.__svelte||(window.__svelte={v:new Set})).v.add("4");const ie=(e,t)=>{const n=t.replace("_","-"),o=new Date(e).toLocaleDateString(n,{year:"numeric",month:"long",day:"numeric",weekday:"long",timeZone:"UTC"});return o.charAt(0).toUpperCase()+o.slice(1)};function le(e){e[8]=e[9].delivery_dates}function re(e,t,n){const o=e.slice();return o[10]=t[n],o}function ce(e){return{c:_,m:_,p:_,d:_}}function de(e){let t,n,o;le(e);let s=G(e[8]),d=[];for(let t=0;t<s.length;t+=1)d[t]=ae(re(e,s,t));return{c(){t=c("select");for(let e=0;e<d.length;e+=1)d[e].c();h(t,"name","okoDeliveryDates"),m(t,"width","100%"),m(t,"margin-bottom","20px"),void 0===e[2]&&N((()=>e[7].call(t)))},m(s,l){i(s,t,l);for(let e=0;e<d.length;e+=1)d[e]&&d[e].m(t,null);g(t,e[2],!0),n||(o=p(t,"change",e[7]),n=!0)},p(e,n){if(le(e),9&n){let o;for(s=G(e[8]),o=0;o<s.length;o+=1){const i=re(e,s,o);d[o]?d[o].p(i,n):(d[o]=ae(i),d[o].c(),d[o].m(t,null))}for(;o<d.length;o+=1)d[o].d(1);d.length=s.length}12&n&&g(t,e[2])},d(e){e&&l(t),r(d,e),n=!1,o()}}}function ae(e){let t,n,o,r,u=ie(e[10],e[0])+"";return{c(){t=c("option"),n=d(u),o=a(),t.__value=r=e[10],$(t,t.__value)},m(e,l){i(e,t,l),s(t,n),s(t,o)},p(e,o){9&o&&u!==(u=ie(e[10],e[0])+"")&&f(n,u),8&o&&r!==(r=e[10])&&(t.__value=r,$(t,t.__value))},d(e){e&&l(t)}}}function ue(e){let t;return{c(){t=c("span"),h(t,"class","skeleton-loader svelte-1tyjt2z")},m(e,n){i(e,t,n)},p:_,d(e){e&&l(t)}}}function pe(e){let t,n,o,r,u,p,$,g={ctx:e,current:null,token:null,hasCatch:!1,pending:ue,then:de,catch:ce,value:9};return Y($=e[3],g),{c(){t=c("div"),n=c("div"),o=d(e[1]),r=a(),u=c("div"),u.textContent="Leveringsdato",p=a(),g.block.c(),h(n,"class","description svelte-1tyjt2z"),h(u,"class","oko-select-headline"),m(u,"font-size","14px")},m(e,l){i(e,t,l),s(t,n),s(n,o),s(t,r),s(t,u),s(t,p),g.block.m(t,g.anchor=null),g.mount=()=>t,g.anchor=null},p(t,[n]){e=t,2&n&&f(o,e[1]),g.ctx=e,8&n&&$!==($=e[3])&&Y($,g)||Z(g,e,n)},i:_,o:_,d(e){e&&l(t),g.block.d(),g.token=null,g=null}}}function he(e,t,n){let o,s,{locale:i}=t,{description:l}=t,{address:r}=t,{postalCode:c}=t,{onSelectDeliveryDate:d}=t;return e.$$set=e=>{"locale"in e&&n(0,i=e.locale),"description"in e&&n(1,l=e.description),"address"in e&&n(4,r=e.address),"postalCode"in e&&n(5,c=e.postalCode),"onSelectDeliveryDate"in e&&n(6,d=e.onSelectDeliveryDate)},e.$$.update=()=>{68&e.$$.dirty&&s&&d(s),48&e.$$.dirty&&n(3,o=se("home-delivery",r,c))},[i,l,s,o,r,c,d,function(){s=v(this),n(2,s),n(3,o),n(4,r),n(5,c)}]}const fe=class extends oe{constructor(e){super(),te(this,e,he,pe,D,{locale:0,description:1,address:4,postalCode:5,onSelectDeliveryDate:6})}};function $e(e,t,n){let o,{map:s}=t,{origin:i}=t;return E((()=>{const e={lng:i.longitude,lat:i.latitude};o=(new window.mapboxgl.Marker).setLngLat(e).addTo(s)})),L((()=>{o&&o.remove()})),e.$$set=e=>{"map"in e&&n(0,s=e.map),"origin"in e&&n(1,i=e.origin)},[s,i]}const me=class extends oe{constructor(e){super(),te(this,e,$e,null,D,{map:0,origin:1})}};function ge(e){let t,n,o,r,u,p,$,m,g,v,k,b,w,S,D=e[0].name+"",x=e[0].address.address+"",C=e[0].address.postal_code+"",M=e[0].address.city+"";return{c(){t=c("div"),n=a(),o=c("div"),r=c("h6"),u=d(D),p=a(),$=c("div"),m=d(x),g=a(),v=c("div"),k=d(C),b=a(),w=d(M),h(t,"class","marker svelte-ge6ukn"),y(t,"selected",e[0].id===e[1]),h(r,"class","svelte-ge6ukn"),h(o,"data-shed",S=e[0].id)},m(l,c){i(l,t,c),e[7](t),i(l,n,c),i(l,o,c),s(o,r),s(r,u),s(o,p),s(o,$),s($,m),s(o,g),s(o,v),s(v,k),s(v,b),s(v,w),e[8](o)},p(e,[n]){3&n&&y(t,"selected",e[0].id===e[1]),1&n&&D!==(D=e[0].name+"")&&f(u,D),1&n&&x!==(x=e[0].address.address+"")&&f(m,x),1&n&&C!==(C=e[0].address.postal_code+"")&&f(k,C),1&n&&M!==(M=e[0].address.city+"")&&f(w,M),1&n&&S!==(S=e[0].id)&&h(o,"data-shed",S)},i:_,o:_,d(s){s&&(l(t),l(n),l(o)),e[7](null),e[8](null)}}}function ve(e,t,n){let o,s,i,{map:l}=t,{shed:r}=t,{selectedShedId:c}=t,{onClick:d}=t,{onClickAway:a}=t;return E((()=>{const e={lng:r.address.longitude,lat:r.address.latitude},t=new window.mapboxgl.Popup({offset:20,closeButton:!1}).setDOMContent(s).on("open",(e=>{d(e.target)})).on("close",a);i=new window.mapboxgl.Marker({element:o}).setLngLat(e).setPopup(t).addTo(l)})),L((()=>{i&&i.remove()})),e.$$set=e=>{"map"in e&&n(4,l=e.map),"shed"in e&&n(0,r=e.shed),"selectedShedId"in e&&n(1,c=e.selectedShedId),"onClick"in e&&n(5,d=e.onClick),"onClickAway"in e&&n(6,a=e.onClickAway)},[r,c,o,s,l,d,a,function(e){O[e?"unshift":"push"]((()=>{o=e,n(2,o)}))},function(e){O[e?"unshift":"push"]((()=>{s=e,n(3,s)}))}]}const ye=class extends oe{constructor(e){super(),te(this,e,ve,ge,D,{map:4,shed:0,selectedShedId:1,onClick:5,onClickAway:6})}};function _e(e,t,n){const o=e.slice();return o[9]=t[n],o}function ke(e){let t,n,o,s=G(e[2]),c=[];for(let t=0;t<s.length;t+=1)c[t]=be(_e(e,s,t));const d=e=>J(c[e],1,1,(()=>{c[e]=null}));let p=e[1]&&we(e);return{c(){for(let e=0;e<c.length;e+=1)c[e].c();t=a(),p&&p.c(),n=u()},m(e,s){for(let t=0;t<c.length;t+=1)c[t]&&c[t].m(e,s);i(e,t,s),p&&p.m(e,s),i(e,n,s),o=!0},p(e,o){if(109&o){let n;for(s=G(e[2]),n=0;n<s.length;n+=1){const i=_e(e,s,n);c[n]?(c[n].p(i,o),U(c[n],1)):(c[n]=be(i),c[n].c(),U(c[n],1),c[n].m(t.parentNode,t))}for(W(),n=s.length;n<c.length;n+=1)d(n);R()}e[1]?p?(p.p(e,o),2&o&&U(p,1)):(p=we(e),p.c(),U(p,1),p.m(n.parentNode,n)):p&&(W(),J(p,1,1,(()=>{p=null})),R())},i(e){if(!o){for(let e=0;e<s.length;e+=1)U(c[e]);U(p),o=!0}},o(e){c=c.filter(Boolean);for(let e=0;e<c.length;e+=1)J(c[e]);J(p),o=!1},d(e){e&&(l(t),l(n)),r(c,e),p&&p.d(e)}}}function be(e){let t,n;return t=new ye({props:{map:e[3],shed:e[9],selectedShedId:e[0],onClickAway:e[6],onClick:e[5](e[9])}}),{c(){X(t.$$.fragment)},m(e,o){K(t,e,o),n=!0},p(e,n){const o={};8&n&&(o.map=e[3]),4&n&&(o.shed=e[9]),1&n&&(o.selectedShedId=e[0]),4&n&&(o.onClick=e[5](e[9])),t.$set(o)},i(e){n||(U(t.$$.fragment,e),n=!0)},o(e){J(t.$$.fragment,e),n=!1},d(e){ee(t,e)}}}function we(e){let t,n;return t=new me({props:{origin:e[1],map:e[3]}}),{c(){X(t.$$.fragment)},m(e,o){K(t,e,o),n=!0},p(e,n){const o={};2&n&&(o.origin=e[1]),8&n&&(o.map=e[3]),t.$set(o)},i(e){n||(U(t.$$.fragment,e),n=!0)},o(e){J(t.$$.fragment,e),n=!1},d(e){ee(t,e)}}}function Se(e){let t,n,o,r,d,p=e[3]&&ke(e);return{c(){t=c("div"),n=c("div"),o=a(),p&&p.c(),r=u(),h(n,"class","map svelte-1cepocy"),h(t,"class","map-wrap svelte-1cepocy")},m(l,c){i(l,t,c),s(t,n),e[8](n),i(l,o,c),p&&p.m(l,c),i(l,r,c),d=!0},p(e,[t]){e[3]?p?(p.p(e,t),8&t&&U(p,1)):(p=ke(e),p.c(),U(p,1),p.m(r.parentNode,r)):p&&(W(),J(p,1,1,(()=>{p=null})),R())},i(e){d||(U(p),d=!0)},o(e){J(p),d=!1},d(n){n&&(l(t),l(o),l(r)),e[8](null),p&&p.d(n)}}}function De(e,t,n){let o,s,i,{origin:l}=t,{sheds:r}=t,{selectedShedId:c}=t;const d=()=>{n(7,i=void 0)};return E((()=>{const e=l?{lng:l.longitude,lat:l.latitude}:{lng:r[0].address.longitude,lat:r[0].address.latitude};n(3,o=new window.mapboxgl.Map({container:s,style:"mapbox://styles/mapbox/streets-v12",center:e,zoom:11}));const t=new window.mapboxgl.LngLatBounds;if(r.forEach((({address:{latitude:e,longitude:n}})=>{const o={lng:n,lat:e};t.extend(o)})),l){const e={lng:l.longitude,lat:l.latitude};t.extend(e)}o.fitBounds(t)})),L((()=>{o&&o.remove()})),e.$$set=e=>{"origin"in e&&n(1,l=e.origin),"sheds"in e&&n(2,r=e.sheds),"selectedShedId"in e&&n(0,c=e.selectedShedId)},e.$$.update=()=>{129&e.$$.dirty&&i&&i.shed.id!==c&&(i.popup.remove(),d())},[c,l,r,o,s,e=>t=>{n(0,c=e.id),n(7,i={popup:t,shed:e})},d,i,function(e){O[e?"unshift":"push"]((()=>{s=e,n(4,s)}))}]}const xe=class extends oe{constructor(e){super(),te(this,e,De,Se,D,{origin:1,sheds:2,selectedShedId:0})}};function Ce(e){e[18]=e[20].origin,e[19]=e[20].sheds;const t=e[19].find((t=>t.id===e[3]));e[21]=t}function Me(e,t,n){const o=e.slice();return o[22]=t[n],o}function Ee(e,t,n){const o=e.slice();return o[25]=t[n],o}function Le(e){return{c:_,m:_,p:_,i:_,o:_,d:_}}function Ie(e){let t,n,o,$,v,_,k,b,S,D,C,M,E,L,I,j,F,T,B,P,z;Ce(e);let H=G(e[19]),V=[];for(let t=0;t<H.length;t+=1)V[t]=Oe(Ee(e,H,t));let q=G(e[21]?e[21].delivery_dates:e[19][0].delivery_dates),Q=[];for(let t=0;t<q.length;t+=1)Q[t]=je(Me(e,q,t));function W(t){e[15](t)}let R={sheds:e[19],origin:e[18]};void 0!==e[3]&&(R.selectedShedId=e[3]),E=new xe({props:R}),O.push((()=>function(e,t,n){const o=e.$$.props.selectedShedId;void 0!==o&&(e.$$.bound[o]=n,n(e.$$.ctx[o]))}(E,0,W)));let Y="modal"===e[0]&&Ae(e),Z="modal"===e[0]&&Fe(e);return{c(){t=c("div"),n=c("div"),o=d(e[2]),$=a(),v=c("div"),v.textContent="Økoskab",_=a(),k=c("select");for(let e=0;e<V.length;e+=1)V[e].c();b=a(),S=c("div"),S.textContent="Leveringsdato",D=a(),C=c("select");for(let e=0;e<Q.length;e+=1)Q[e].c();M=a(),X(E.$$.fragment),I=a(),Y&&Y.c(),F=a(),Z&&Z.c(),T=u(),h(n,"class","description svelte-amce9"),h(v,"class","oko-select-headline"),m(v,"font-size","14px"),h(k,"name","okoLocations"),h(k,"id","locationsDropdown"),m(k,"width","100%"),m(k,"margin-top","0"),m(k,"margin-bottom","20px"),void 0===e[3]&&N((()=>e[13].call(k))),h(S,"class","oko-select-headline"),m(S,"font-size","14px"),h(C,"name","okoDeliveryDates"),m(C,"width","100%"),m(C,"margin-bottom","20px"),void 0===e[4]&&N((()=>e[14].call(C))),h(t,"class",j=x(e[0])+" svelte-amce9"),y(t,"hidden",!e[5])},m(l,r){i(l,t,r),s(t,n),s(n,o),s(t,$),s(t,v),s(t,_),s(t,k);for(let e=0;e<V.length;e+=1)V[e]&&V[e].m(k,null);g(k,e[3],!0),s(t,b),s(t,S),s(t,D),s(t,C);for(let e=0;e<Q.length;e+=1)Q[e]&&Q[e].m(C,null);g(C,e[4],!0),s(t,M),K(E,t,null),s(t,I),Y&&Y.m(t,null),i(l,F,r),Z&&Z.m(l,r),i(l,T,r),B=!0,P||(z=[p(k,"change",e[13]),p(C,"change",e[14])],P=!0)},p(e,n){if(Ce(e),(!B||4&n)&&f(o,e[2]),64&n){let t;for(H=G(e[19]),t=0;t<H.length;t+=1){const o=Ee(e,H,t);V[t]?V[t].p(o,n):(V[t]=Oe(o),V[t].c(),V[t].m(k,null))}for(;t<V.length;t+=1)V[t].d(1);V.length=H.length}if(72&n&&g(k,e[3]),74&n){let t;for(q=G(e[21]?e[21].delivery_dates:e[19][0].delivery_dates),t=0;t<q.length;t+=1){const o=Me(e,q,t);Q[t]?Q[t].p(o,n):(Q[t]=je(o),Q[t].c(),Q[t].m(C,null))}for(;t<Q.length;t+=1)Q[t].d(1);Q.length=q.length}88&n&&g(C,e[4]);const s={};var i;64&n&&(s.sheds=e[19]),64&n&&(s.origin=e[18]),!L&&8&n&&(L=!0,s.selectedShedId=e[3],i=()=>L=!1,A.push(i)),E.$set(s),"modal"===e[0]?Y?Y.p(e,n):(Y=Ae(e),Y.c(),Y.m(t,null)):Y&&(Y.d(1),Y=null),(!B||1&n&&j!==(j=x(e[0])+" svelte-amce9"))&&h(t,"class",j),(!B||33&n)&&y(t,"hidden",!e[5]),"modal"===e[0]?Z?Z.p(e,n):(Z=Fe(e),Z.c(),Z.m(T.parentNode,T)):Z&&(Z.d(1),Z=null)},i(e){B||(U(E.$$.fragment,e),B=!0)},o(e){J(E.$$.fragment,e),B=!1},d(e){e&&(l(t),l(F),l(T)),r(V,e),r(Q,e),ee(E),Y&&Y.d(),Z&&Z.d(e),P=!1,w(z)}}}function Oe(e){let t,n,o,r,u=e[25].name+"";return{c(){t=c("option"),n=d(u),o=a(),t.__value=r=e[25].id,$(t,t.__value)},m(e,l){i(e,t,l),s(t,n),s(t,o)},p(e,o){64&o&&u!==(u=e[25].name+"")&&f(n,u),64&o&&r!==(r=e[25].id)&&(t.__value=r,$(t,t.__value))},d(e){e&&l(t)}}}function je(e){let t,n,o,r,u=ie(e[22],e[1])+"";return{c(){t=c("option"),n=d(u),o=a(),t.__value=r=e[22],$(t,t.__value)},m(e,l){i(e,t,l),s(t,n),s(t,o)},p(e,o){74&o&&u!==(u=ie(e[22],e[1])+"")&&f(n,u),72&o&&r!==(r=e[22])&&(t.__value=r,$(t,t.__value))},d(e){e&&l(t)}}}function Ae(e){let t,n,o,r,d,u,f;return{c(){t=c("div"),n=c("div"),o=a(),r=c("a"),r.textContent="Done",h(n,"class","okoButtonModalContent"),h(r,"href",d="#"),h(r,"class","button"),h(t,"class","okoButtonModal okoButtonModalDone")},m(l,c){i(l,t,c),s(t,n),s(t,o),s(t,r),u||(f=p(r,"click",e[8]),u=!0)},p:_,d(e){e&&l(t),u=!1,f()}}}function Fe(e){let t,n,o,r,u,$,g,v,y,_,k,b,w,S,D,x,C,M,E,L,I=e[21]?.name+"",O=(e[4]&&ie(e[4],e[1]))+"";return{c(){t=c("div"),n=d(e[2]),o=a(),r=c("div"),u=c("div"),$=c("span"),$.textContent="Økoskab:",g=a(),v=c("span"),y=d(I),_=a(),k=c("div"),b=c("span"),b.textContent="Levering:",w=a(),S=c("span"),D=d(O),x=a(),C=c("a"),C.textContent="Vælg Økoskab",h(t,"class","description svelte-amce9"),h($,"class","oko-shed-content-label svelte-amce9"),h(v,"class","oko-shed-content-value svelte-amce9"),h(u,"id","oko-shed-content-location"),h(b,"class","oko-shed-content-label svelte-amce9"),h(S,"class","oko-shed-content-value svelte-amce9"),h(k,"id","oko-shed-content-date"),h(r,"id","oko-shed-content"),h(r,"class","svelte-amce9"),h(C,"href",M="#"),h(C,"class","button"),m(C,"margin-bottom","20px")},m(l,c){i(l,t,c),s(t,n),i(l,o,c),i(l,r,c),s(r,u),s(u,$),s(u,g),s(u,v),s(v,y),s(r,_),s(r,k),s(k,b),s(k,w),s(k,S),s(S,D),i(l,x,c),i(l,C,c),E||(L=p(C,"click",e[7]),E=!0)},p(e,t){4&t&&f(n,e[2]),72&t&&I!==(I=e[21]?.name+"")&&f(y,I),18&t&&O!==(O=(e[4]&&ie(e[4],e[1]))+"")&&f(D,O)},d(e){e&&(l(t),l(o),l(r),l(x),l(C)),E=!1,L()}}}function Te(e){let t,n,o,r;return{c(){t=c("div"),n=d(e[2]),o=a(),r=c("div"),r.innerHTML='<span class="skeleton-loader svelte-amce9"></span>',h(t,"class","description svelte-amce9"),h(r,"class","skeleton-container svelte-amce9")},m(e,l){i(e,t,l),s(t,n),i(e,o,l),i(e,r,l)},p(e,t){4&t&&f(n,e[2])},i:_,o:_,d(e){e&&(l(t),l(o),l(r))}}}function Ne(e){let t,n,o,s={ctx:e,current:null,token:null,hasCatch:!1,pending:Te,then:Ie,catch:Le,value:20,blocks:[,,,]};return Y(n=e[6],s),{c(){t=u(),s.block.c()},m(e,n){i(e,t,n),s.block.m(e,s.anchor=n),s.mount=()=>t.parentNode,s.anchor=t,o=!0},p(t,[o]){e=t,s.ctx=e,64&o&&n!==(n=e[6])&&Y(n,s)||Z(s,e,o)},i(e){o||(U(s.block),o=!0)},o(e){for(let e=0;e<3;e+=1)J(s.blocks[e]);o=!1},d(e){e&&l(t),s.block.d(e),s.token=null,s=null}}}function Be(e,t,n){let o;var s=this&&this.__awaiter||function(e,t,n,o){return new(n||(n=Promise))((function(s,i){function l(e){try{c(o.next(e))}catch(e){i(e)}}function r(e){try{c(o.throw(e))}catch(e){i(e)}}function c(e){var t;e.done?s(e.value):(t=e.value,t instanceof n?t:new n((function(e){e(t)}))).then(l,r)}c((o=o.apply(e,t||[])).next())}))};let i,l,{displayMode:r}=t,{locale:c}=t,{description:d}=t,{address:a}=t,{postalCode:u}=t,{onSelectShed:p=(()=>{})}=t,{onSelectDeliveryDate:h}=t,f="inline"===r;return e.$$set=e=>{"displayMode"in e&&n(0,r=e.displayMode),"locale"in e&&n(1,c=e.locale),"description"in e&&n(2,d=e.description),"address"in e&&n(9,a=e.address),"postalCode"in e&&n(10,u=e.postalCode),"onSelectShed"in e&&n(11,p=e.onSelectShed),"onSelectDeliveryDate"in e&&n(12,h=e.onSelectDeliveryDate)},e.$$.update=()=>{2056&e.$$.dirty&&i&&(p(i),function(){s(this,void 0,void 0,(function*(){if(i){const{sheds:e}=yield o,t=e.find((e=>e.id===i)),s=null==t?void 0:t.delivery_dates;l&&(null==t?void 0:t.delivery_dates.includes(l))||n(4,l=s&&s[0])}else n(4,l=void 0)}))}()),4112&e.$$.dirty&&l&&h(l),1536&e.$$.dirty&&n(6,o=se("shed-delivery",a,u))},[r,c,d,i,l,f,o,function(e){e.preventDefault(),n(5,f=!0)},function(e){e.preventDefault(),n(5,f=!1)},a,u,p,h,function(){i=v(this),n(3,i),n(6,o),n(9,a),n(10,u)},function(){l=v(this),n(4,l),n(6,o),n(9,a),n(10,u),n(3,i)},function(e){i=e,n(3,i)}]}const Pe=class extends oe{constructor(e){super(),te(this,e,Be,Ne,D,{displayMode:0,locale:1,description:2,address:9,postalCode:10,onSelectShed:11,onSelectDeliveryDate:12})}};function ze(e){let t,n;return t=new fe({props:{locale:e[0],address:e[4],postalCode:e[5],onSelectDeliveryDate:e[7],description:e[2].homeDeliveryDescription}}),{c(){X(t.$$.fragment)},m(e,o){K(t,e,o),n=!0},p(e,n){const o={};1&n&&(o.locale=e[0]),16&n&&(o.address=e[4]),32&n&&(o.postalCode=e[5]),128&n&&(o.onSelectDeliveryDate=e[7]),4&n&&(o.description=e[2].homeDeliveryDescription),t.$set(o)},i(e){n||(U(t.$$.fragment,e),n=!0)},o(e){J(t.$$.fragment,e),n=!1},d(e){ee(t,e)}}}function He(e){let t,n;return t=new Pe({props:{displayMode:e[1],locale:e[0],address:e[4],postalCode:e[5],onSelectShed:e[6],onSelectDeliveryDate:e[7],description:e[2].shedDeliveryDescription}}),{c(){X(t.$$.fragment)},m(e,o){K(t,e,o),n=!0},p(e,n){const o={};2&n&&(o.displayMode=e[1]),1&n&&(o.locale=e[0]),16&n&&(o.address=e[4]),32&n&&(o.postalCode=e[5]),64&n&&(o.onSelectShed=e[6]),128&n&&(o.onSelectDeliveryDate=e[7]),4&n&&(o.description=e[2].shedDeliveryDescription),t.$set(o)},i(e){n||(U(t.$$.fragment,e),n=!0)},o(e){J(t.$$.fragment,e),n=!1},d(e){ee(t,e)}}}function Ve(e){let t,n,o,s;const r=[He,ze],c=[];function d(e,t){return"shed-delivery"===e[3]?0:"home-delivery"===e[3]?1:-1}return~(t=d(e))&&(n=c[t]=r[t](e)),{c(){n&&n.c(),o=u()},m(e,n){~t&&c[t].m(e,n),i(e,o,n),s=!0},p(e,[s]){let i=t;t=d(e),t===i?~t&&c[t].p(e,s):(n&&(W(),J(c[i],1,1,(()=>{c[i]=null})),R()),~t?(n=c[t],n?n.p(e,s):(n=c[t]=r[t](e),n.c()),U(n,1),n.m(o.parentNode,o)):n=null)},i(e){s||(U(n),s=!0)},o(e){J(n),s=!1},d(e){e&&l(o),~t&&c[t].d(e)}}}function qe(e,t,n){let{locale:o}=t,{displayMode:s}=t,{strings:i}=t,{shippingMethod:l}=t,{address:r}=t,{postalCode:c}=t,{onSelectShed:d}=t,{onSelectDeliveryDate:a}=t;return e.$$set=e=>{"locale"in e&&n(0,o=e.locale),"displayMode"in e&&n(1,s=e.displayMode),"strings"in e&&n(2,i=e.strings),"shippingMethod"in e&&n(3,l=e.shippingMethod),"address"in e&&n(4,r=e.address),"postalCode"in e&&n(5,c=e.postalCode),"onSelectShed"in e&&n(6,d=e.onSelectShed),"onSelectDeliveryDate"in e&&n(7,a=e.onSelectDeliveryDate)},[o,s,i,l,r,c,d,a]}const Qe=class extends oe{constructor(e){super(),te(this,e,qe,Ve,D,{locale:0,displayMode:1,strings:2,shippingMethod:3,address:4,postalCode:5,onSelectShed:6,onSelectDeliveryDate:7})}},We='input[name="shipping_method[0]"]:checked',Re='input[name="shipping_method[0]"][type="hidden"]';class Ue{constructor(e,t,n){this.locale=e,this.displayOption=t,this.homeDeliveryDescription=n.homeDelivery,this.shedDeliveryDescription=n.shedDelivery,this.attachEventListeners()}attachEventListeners(){const e=jQuery,t=this;e(document).on("updated_checkout",(function(){t.setLocationInput(""),t.setDeliveryDateInput(""),t.deliveryOptions?t.updateShippingOptions():t.populateShippingOptions()})),e(document).on("change","input.shipping_method",(function(){t.deliveryOptions?.$destroy(),t.deliveryOptions=void 0}))}populateShippingOptions(){const e=this.getShippingData();if(!e)return;const t=this.createSvelteTarget();if(!t)return void console.error("Failed to populate shipping options - no target element found");const{shippingMethod:n,address:o,postalCode:s}=e;this.deliveryOptions=new Qe({target:t,props:{displayMode:this.displayOption,shippingMethod:n,address:o,postalCode:s,locale:this.locale,strings:{shedDeliveryDescription:this.shedDeliveryDescription,homeDeliveryDescription:this.homeDeliveryDescription},onSelectShed:e=>{this.setLocationInput(e)},onSelectDeliveryDate:e=>{this.setDeliveryDateInput(e)}}})}updateShippingOptions(){const e=this.getShippingData();if(!e)return;const{shippingMethod:t,address:n,postalCode:o}=e;this.deliveryOptions?.$set({shippingMethod:t,address:n,postalCode:o})}createSvelteTarget(){const e=this.getSelectedShippingMethodElement()?.parentElement;if(e){const t=document.createElement("div");return t.id="okoskabet-shipping",e.after(t),t}return null}getShippingData(){const e=this.getSelectedShippingMethod(),t=this.getFormFieldValue("#billing_postcode"),n=[this.getFormFieldValue("#billing_address_1"),this.getFormFieldValue("#billing_address_2")].filter((e=>e&&""!==e)).join(", ");if(e&&t)return{shippingMethod:e,address:n,postalCode:t}}getSelectedShippingMethodElement(){const e=document.querySelector(We),t=document.querySelector(Re);return e instanceof HTMLInputElement?e:t instanceof HTMLInputElement?t:void 0}getSelectedShippingMethod(){const e=this.getFormFieldValue(We),t=this.getFormFieldValue(Re);switch(e||t){case"hey_okoskabet_shipping_home":return"home-delivery";case"hey_okoskabet_shipping_shed":return"shed-delivery";default:return}}getFormFieldValue(e){const t=document.querySelector(e);if(t instanceof HTMLInputElement)return t.value}setDeliveryDateInput(e){jQuery("#billing_okoskabet_delivery_date").val(e)}setLocationInput(e){jQuery("#billing_okoskabet_shed_id").val(e)}}window.onload=()=>{window.mapboxgl.accessToken="pk.eyJ1IjoiZGFub2tvc2thYmV0IiwiYSI6ImNsOTN5enc5eDF0OXgzcW10ejgyMDI3ZHIifQ.Yy_h5jy-F0E2t0EvnElFag",jQuery((function(){const{locale:e,displayOption:t,descriptions:n}=window._okoskabet_checkout;new Ue(e,t,n)}))}})();