import { mount } from 'svelte';
import App from './App.svelte';
import { installModalBackdrop } from './lib/modalBackdrop';
import './app.css';

installModalBackdrop();

const target = document.getElementById('app');
if (target) {
  mount(App, { target });
}
