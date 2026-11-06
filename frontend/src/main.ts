import { mount } from 'svelte';
import App from './App.svelte';
import { installModalBackdrop } from './lib/modalBackdrop';
import { installSelectOnFocus } from './lib/selectOnFocus';
import './app.css';

installModalBackdrop();
installSelectOnFocus();

const target = document.getElementById('app');
if (target) {
  mount(App, { target });
}
