/*
 * Shows and hides the fields of a built form, and makes fields required, when their conditions
 * depend on other answers of the same step. Conditions on answers of earlier steps are already
 * decided by the server.
 *
 * The comparison rules mirror ConditionEvaluator.php: a field hidden here is a field the server
 * ignores, so a hidden required field never blocks a submission. A hidden field is also disabled,
 * so the browser neither validates nor sends it.
 *
 * It also adds and removes the rows of a repeater, from the prototype the server rendered.
 *
 * No dependencies, no build step: include it once, it handles every form on the page, including
 * forms that are added later (Turbo, Live Components).
 */
(function (root, factory) {
    var api = factory();

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.EekesFormBuilder = root.EekesFormBuilder || api;
        api.start(root.document);
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var started = false;

    function strings(value) {
        var values = Array.isArray(value) ? value : [value];
        var result = [];

        values.forEach(function (item) {
            // The rows of a repeater: every answer of every row counts.
            if (item !== null && typeof item === 'object') {
                Array.prototype.push.apply(result, strings(Array.isArray(item) ? item : Object.keys(item).map(function (key) { return item[key]; })));

                return;
            }

            if (item === true) {
                item = '1';
            }

            if (item === null || item === undefined || item === false || typeof item === 'object') {
                return;
            }

            item = String(item).trim().toLowerCase();

            if (item !== '') {
                result.push(item);
            }
        });

        return result;
    }

    function isMet(condition, values) {
        var actual = strings(values[condition.field]);
        var expected = String(condition.value === null || condition.value === undefined ? '' : condition.value).trim().toLowerCase();
        var contains = actual.some(function (item) {
            return expected === '' || item.indexOf(expected) !== -1;
        });

        switch (condition.operator) {
            case 'filled':
                return actual.length > 0;
            case 'empty':
                return actual.length === 0;
            case 'equals':
                return actual.indexOf(expected) !== -1;
            case 'not_equals':
                return actual.indexOf(expected) === -1;
            case 'contains':
                return contains;
            case 'not_contains':
                return !contains;
            default:
                return true;
        }
    }

    /**
     * A set of rules combined with "all" (AND) or "any" (OR); a rule is a condition or a nested
     * set. A single condition is accepted as a set of one.
     */
    function isSetMet(set, values) {
        if (!set) {
            return true;
        }

        if (Object.prototype.hasOwnProperty.call(set, 'field')) {
            return isMet(set, values);
        }

        var rules = Array.isArray(set.rules) ? set.rules : [];
        var test = function (rule) {
            return isSetMet(rule, values);
        };

        return set.match === 'any' ? rules.some(test) : rules.every(test);
    }

    /**
     * The field key of an input: the last bracket of eekes_form_x[step][key], or the one before a
     * trailing [] of a group of checkboxes.
     */
    function fieldKey(name) {
        var match = /\[([^\[\]]+)\](\[\])?$/.exec(name || '');

        return match ? match[1] : null;
    }

    function values(form) {
        var result = {};
        var repeaters = {};

        try {
            result = JSON.parse(form.getAttribute('data-eekes-values') || '{}') || {};
        } catch (error) {
            result = {};
        }

        Array.prototype.forEach.call(form.elements, function (element) {
            var key = fieldKey(element.name);

            if (!key || element.type === 'file' || element.type === 'submit' || element.type === 'button') {
                return;
            }

            // An input in a row of a repeater is an answer of the repeater, not a field of its own.
            var repeater = element.closest ? element.closest('[data-eekes-repeater]') : null;

            if (repeater) {
                var repeaterKey = repeater.getAttribute('data-eekes-repeater');

                // A repeater on this page replaces whatever an earlier step said about it.
                if (!repeaters[repeaterKey]) {
                    repeaters[repeaterKey] = true;
                    result[repeaterKey] = [];
                }

                repeaterValue(result[repeaterKey], element);

                return;
            }

            var multiple = /\[\]$/.test(element.name) || element.multiple;

            if (multiple && !Array.isArray(result[key])) {
                result[key] = [];
            }

            if (element.type === 'checkbox' || element.type === 'radio') {
                if (element.checked) {
                    if (multiple) {
                        result[key].push(element.value);
                    } else {
                        result[key] = element.type === 'checkbox' && element.value === '1' ? true : element.value;
                    }
                } else if (!multiple && !(key in result)) {
                    result[key] = null;
                }

                return;
            }

            if (element.tagName === 'SELECT' && element.multiple) {
                Array.prototype.forEach.call(element.selectedOptions, function (option) {
                    result[key].push(option.value);
                });

                return;
            }

            result[key] = element.value;
        });

        return result;
    }

    /**
     * Adds an input of a repeater to the repeater's answers, as one flat list: the conditions only
     * ask whether it is filled or contains something, which the server answers over all rows.
     */
    function repeaterValue(list, element) {
        if ((element.type === 'checkbox' || element.type === 'radio') && !element.checked) {
            return;
        }

        if (element.tagName === 'SELECT' && element.multiple) {
            Array.prototype.forEach.call(element.selectedOptions, function (option) {
                list.push(option.value);
            });

            return;
        }

        list.push(element.type === 'checkbox' && element.value === '1' ? true : element.value);
    }

    function parse(row, attribute) {
        try {
            return JSON.parse(row.getAttribute(attribute));
        } catch (error) {
            return null;
        }
    }

    function apply(form) {
        var current = values(form);

        Array.prototype.forEach.call(form.querySelectorAll('[data-eekes-condition]'), function (row) {
            var visible = isSetMet(parse(row, 'data-eekes-condition'), current);

            row.hidden = !visible;
            Array.prototype.forEach.call(row.querySelectorAll('input, select, textarea'), function (input) {
                input.disabled = !visible;
            });
        });

        // A field that becomes required shows it the way a required field always does: the
        // required attribute and the "required" class Symfony puts on its label.
        Array.prototype.forEach.call(form.querySelectorAll('[data-eekes-required-if]'), function (row) {
            var required = isSetMet(parse(row, 'data-eekes-required-if'), current);

            // The sub-fields of a repeater keep their own requirement: a required repeater asks
            // for a row, not for every sub-field.
            Array.prototype.forEach.call(row.querySelectorAll('input, select, textarea'), function (input) {
                if (!inRepeaterRow(input)) {
                    input.required = required;
                }
            });
            Array.prototype.forEach.call(row.querySelectorAll('label'), function (label) {
                if (!inRepeaterRow(label)) {
                    label.classList.toggle('required', required);
                }
            });
        });
    }

    function inRepeaterRow(element) {
        return !!(element.closest && element.closest('[data-eekes-repeater-row]'));
    }

    function repeaterRows(repeater) {
        var container = repeater.querySelector('.eekes-form-repeater-rows');

        return container ? Array.prototype.filter.call(container.children, function (row) {
            return row.hasAttribute('data-eekes-repeater-row');
        }) : [];
    }

    /**
     * Shows "add" while there is room for another row and "remove" while there are more rows than
     * the minimum, and numbers the rows.
     */
    function updateRepeater(repeater) {
        var rows = repeaterRows(repeater);
        var min = parseInt(repeater.getAttribute('data-eekes-repeater-min'), 10) || 0;
        var max = parseInt(repeater.getAttribute('data-eekes-repeater-max'), 10) || 0;
        var add = repeater.querySelector('[data-eekes-repeater-add]');

        if (add && add.closest('[data-eekes-repeater]') === repeater) {
            add.hidden = max > 0 && rows.length >= max;
        }

        rows.forEach(function (row, index) {
            var remove = row.querySelector('[data-eekes-repeater-remove]');
            var number = row.querySelector('[data-eekes-repeater-number]');

            if (remove) {
                remove.hidden = rows.length <= Math.max(min, 1);
            }

            if (number) {
                number.textContent = String(index + 1);
            }
        });
    }

    /**
     * One past the highest index in use. Not the number of rows: after a failed submit the server
     * renders the rows under the indexes they were sent with, which may have gaps.
     */
    function nextRepeaterIndex(repeater) {
        var key = repeater.getAttribute('data-eekes-repeater').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        var pattern = new RegExp('\\[' + key + '\\]\\[(\\d+)\\]');
        var next = 0;

        repeaterRows(repeater).forEach(function (row) {
            Array.prototype.forEach.call(row.querySelectorAll('[name]'), function (element) {
                var match = pattern.exec(element.name);

                if (match) {
                    next = Math.max(next, parseInt(match[1], 10) + 1);
                }
            });
        });

        return next;
    }

    function addRepeaterRow(repeater) {
        var container = repeater.querySelector('.eekes-form-repeater-rows');
        var prototype = repeater.getAttribute('data-prototype');

        if (!container || !prototype) {
            return;
        }

        // A new index every time: the indexes of rows removed before must not be reused.
        var index = parseInt(repeater.getAttribute('data-eekes-repeater-index'), 10) || 0;

        repeater.setAttribute('data-eekes-repeater-index', String(index + 1));

        var template = repeater.ownerDocument.createElement('template');
        template.innerHTML = prototype.replace(/__row__/g, String(index)).trim();

        var row = template.content.firstElementChild;

        if (!row) {
            return;
        }

        container.appendChild(row);
        updateRepeater(repeater);

        var first = row.querySelector('input, select, textarea');

        if (first) {
            first.focus();
        }
    }

    function initRepeaters(form) {
        Array.prototype.forEach.call(form.querySelectorAll('[data-eekes-repeater]'), function (repeater) {
            repeater.setAttribute('data-eekes-repeater-index', String(nextRepeaterIndex(repeater)));
            updateRepeater(repeater);
        });

        form.addEventListener('click', function (event) {
            var target = event.target && event.target.closest ? event.target : null;
            var add = target ? target.closest('[data-eekes-repeater-add]') : null;
            var remove = target ? target.closest('[data-eekes-repeater-remove]') : null;

            if (add) {
                event.preventDefault();
                addRepeaterRow(add.closest('[data-eekes-repeater]'));
                apply(form);
            } else if (remove) {
                var repeater = remove.closest('[data-eekes-repeater]');

                event.preventDefault();
                remove.closest('[data-eekes-repeater-row]').remove();
                updateRepeater(repeater);
                apply(form);
            }
        });
    }

    function init(form) {
        if (form.eekesFormBuilder) {
            return;
        }

        form.eekesFormBuilder = true;
        form.addEventListener('input', function () { apply(form); });
        form.addEventListener('change', function () { apply(form); });
        initRepeaters(form);
        apply(form);
    }

    function scan(document) {
        Array.prototype.forEach.call(document.querySelectorAll('form.eekes-form'), init);
    }

    function start(document) {
        if (started || !document) {
            return;
        }

        started = true;

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { scan(document); });
        } else {
            scan(document);
        }

        // Forms rendered later, by Turbo or a Live Component.
        if (typeof MutationObserver !== 'undefined') {
            new MutationObserver(function () { scan(document); }).observe(document.documentElement, {childList: true, subtree: true});
        }
    }

    return {apply: apply, fieldKey: fieldKey, init: init, isMet: isMet, isSetMet: isSetMet, start: start, values: values};
});
